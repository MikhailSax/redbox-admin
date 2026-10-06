<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Lead;
use App\Entity\MediaPlan;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\BookingStatus;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * An agent adds clients, fills media plans, works the website's requests and looks at the catalogue,
 * makes 24h holds, but changes no structure, confirms no bookings and keeps no payments.
 */
final class AgentAccessTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    protected ?string $loginAs = User::ROLE_AGENT;

    private Product $billboard;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-09-10 12:00:00');

        $category = (new Category())->setName('Билборд 6х3');
        $district = (new District())->setName('Центральный');
        $static = (new ProductType())->setName('Статика');
        $this->billboard = (new Product())->setName('Щит на Ленина')->setCategory($category)->setProductType($static)->setDistrict($district)
            ->setPrice('40000')->setLatitude('51.83')->setLongitude('107.58')
            ->addSide((new ProductSide())->setName('A'))->addSide((new ProductSide())->setName('B'));
        foreach ([$category, $district, $static, $this->billboard] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testSidebarHasOnlyTheAgentsSections(): void
    {
        // the dashboard is for managers: an agent lands on the media plans
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/media-plans');

        $crawler = $this->client->request('GET', '/admin/media-plans');
        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('#default-sidebar nav')->text();
        foreach (['Конструкции', 'Карта', 'Медиапланы', 'Заявки', 'Клиенты'] as $item) {
            self::assertStringContainsString($item, $menu);
        }
        foreach (['Обзор', 'Брони', 'Платежи', 'Акции', 'Партнёры', 'Категории', 'Пользователи'] as $item) {
            self::assertStringNotContainsString($item, $menu);
        }
        self::assertSelectorTextContains('#default-sidebar', 'Агент');
    }

    public function testManagersSectionsAreClosed(): void
    {
        foreach (['/admin/bookings', '/admin/payments', '/admin/promotions', '/admin/partners', '/admin/categories', '/admin/types', '/admin/districts', '/admin/services', '/admin/users', '/admin/products/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testCatalogueIsReadOnly(): void
    {
        $crawler = $this->client->request('GET', '/admin/products');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Щит на Ленина');
        self::assertCount(0, $crawler->filter('form[action$="/delete"]'));
        self::assertCount(0, $crawler->filter('a[href="/admin/products/new"]'));

        // the card opens with every field disabled and nothing to save
        $crawler = $this->client->request('GET', '/admin/products/'.$this->billboard->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame('disabled', $crawler->filter('input[name="product_form[name]"]')->attr('disabled'));
        self::assertCount(0, $crawler->selectButton('Сохранить'));
        self::assertSelectorTextContains('main', 'Карточка только для просмотра');
        self::assertSelectorNotExists('[data-collection-add], [data-collection-remove]');

        // a forged save is refused and changes nothing
        $this->client->request('POST', '/admin/products/'.$this->billboard->getId().'/edit', ['product_form' => ['name' => 'Взлом']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/products/'.$this->billboard->getId().'/delete');
        self::assertFalse($this->client->getResponse()->isSuccessful()); // no token to delete with: the page has no such form
        $this->em->clear();
        self::assertSame('Щит на Ленина', $this->em->find(Product::class, $this->billboard->getId())->getName());

        $this->client->request('GET', '/admin/map');
        self::assertResponseIsSuccessful();
    }

    public function testAgentBooksButConfirmingIsForManagers(): void
    {
        $customer = $this->createClientCard();

        $crawler = $this->client->request('GET', '/admin/products/'.$this->billboard->getId().'/bookings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Занятость на 12 месяцев');
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $this->billboard->getSides()->first()->getId();
        $values['booking_form']['startMonth'] = '2026-10';
        $values['booking_form']['months'] = '1';
        $values['booking_form']['client'] = (string) $customer->getId();
        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/products/'.$this->billboard->getId().'/bookings');

        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertSame(BookingStatus::Hold, $booking->getStatus());
        self::assertSame('me@redbox.local', $booking->getCreatedBy()?->getEmail());

        // the hold is in the list, without the manager's buttons and money
        $crawler = $this->client->request('GET', '/admin/products/'.$this->billboard->getId().'/bookings?month=all');
        self::assertStringContainsString('Ждёт подтверждения', $crawler->filter('#bookings ~ .table-card tbody')->text());
        self::assertCount(0, $crawler->filter('form[action^="/admin/bookings/"]'));
        self::assertSelectorNotExists('[data-revenue]');

        // confirming, paying and cancelling are refused
        foreach (['confirm', 'pay', 'cancel'] as $action) {
            $this->client->request('POST', '/admin/bookings/'.$booking->getId().'/'.$action);
            self::assertFalse($this->client->getResponse()->isSuccessful(), $action);
        }
        $this->em->clear();
        self::assertSame(BookingStatus::Hold, $this->em->find(Booking::class, $booking->getId())->getStatus());
    }

    public function testAgentAddsAClientAndFillsAMediaPlan(): void
    {
        $crawler = $this->client->request('GET', '/admin/clients/new');
        self::assertResponseIsSuccessful();
        [$values, $uri] = $this->formValues($crawler, 'Добавить клиента');
        $values['client_form']['clientType'] = 'legal';
        $values['client_form']['company'] = 'ТЦ ВОСТОК ООО';
        $values['client_form']['inn'] = '0323340212';
        $this->submit($uri, $values);

        $client = $this->em->getRepository(User::class)->findOneBy(['inn' => '0323340212']);
        self::assertResponseRedirects('/admin/clients/'.$client->getId());
        $crawler = $this->client->followRedirect();
        // only the profile: documents, payments and bookings are for managers
        self::assertCount(1, $crawler->filter('[role=tablist] [data-tab]'));
        self::assertSelectorNotExists('form[action$="/delete"]');

        $crawler = $this->client->request('GET', '/admin/media-plans/new');
        [$values, $uri] = $this->formValues($crawler, 'Создать и подобрать конструкции');
        $values['media_plan_form']['title'] = 'Осень';
        $values['media_plan_form']['client'] = (string) $client->getId();
        $values['media_plan_form']['startMonth'] = '2026-10';
        $values['media_plan_form']['months'] = '1';
        $this->submit($uri, $values);
        $plan = $this->em->getRepository(MediaPlan::class)->findOneBy(['title' => 'Осень']);
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId());

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'?q=Ленина');
        $this->client->submit($crawler->filter('#plan-picker form')->first()->form());
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId());

        // the agent books the plan; confirming the holds, its payments and deleting it are for managers
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        self::assertCount(1, $this->em->find(MediaPlan::class, $plan->getId())->getItems());
        self::assertSelectorNotExists('#payments');
        $this->client->submit($crawler->filter('form[action$="/book"]')->form());
        self::assertResponseRedirects('/admin/media-plans/'.$plan->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Создано броней: 1');
        self::assertSame(BookingStatus::Hold, $this->em->getRepository(Booking::class)->findOneBy([])->getStatus());
        self::assertSelectorNotExists('form[action$="/bookings/confirm"]');

        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        $token = $crawler->filter('form[action$="/items"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/media-plans/'.$plan->getId().'/bookings/confirm', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAgentWorksTheWebsiteRequests(): void
    {
        $lead = (new Lead())->setContactName('Иван')->setPhone('+7 900 000-00-00');
        $this->em->persist($lead);
        $this->em->flush();

        $this->client->request('GET', '/admin/leads');
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/admin/leads/'.$lead->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/delete"]');
    }
}
