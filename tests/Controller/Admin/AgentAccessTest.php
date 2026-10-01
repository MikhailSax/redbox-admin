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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * An agent adds clients, fills media plans, works the website's requests and looks at the catalogue,
 * but changes no structure and makes no bookings or payments.
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

        // the map and the occupancy are there to look at, without booking
        $this->client->request('GET', '/admin/map');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/map/data');
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['canBook']);

        $crawler = $this->client->request('GET', '/admin/products/'.$this->billboard->getId().'/bookings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Занятость на 12 месяцев');
        self::assertCount(0, $crawler->selectButton('Забронировать на 24 часа'));
        $this->client->request('POST', '/admin/products/'.$this->billboard->getId().'/bookings', ['booking_form' => []]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(Booking::class)->count([]));
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

        // the plan is filled, but booking it, its payments and deleting it are for managers
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId());
        self::assertCount(1, $this->em->find(MediaPlan::class, $plan->getId())->getItems());
        self::assertSelectorNotExists('form[action$="/book"]');
        self::assertSelectorNotExists('form[action$="/bookings/confirm"]');
        self::assertSelectorNotExists('#payments');
        $token = $crawler->filter('form[action$="/items"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/media-plans/'.$plan->getId().'/book', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(Booking::class)->count([]));
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
