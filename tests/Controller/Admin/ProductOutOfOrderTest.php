<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\MediaPlan;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Service\BookingException;
use App\Service\BookingManager;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A structure marked as not working: kept in the CRM, left out of the dashboard, the website, media plans and new bookings.
 */
final class ProductOutOfOrderTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private Product $working;
    private Product $broken;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-09-10 12:00:00');

        $category = (new Category())->setName('Билборд 6х3');
        $district = (new District())->setName('Центральный');
        $static = (new ProductType())->setName('Статика');
        $this->working = $this->product('Щит на Ленина', $category, $static, $district);
        $this->broken = $this->product('Щит на Мира', $category, $static, $district);

        foreach ([$category, $district, $static, $this->working, $this->broken] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testMarkedInTheCardWithAReason(): void
    {
        // a booking made while it worked stays
        $booking = $this->hold($this->broken);

        $crawler = $this->client->request('GET', '/admin/products/'.$this->broken->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('1', $values['product_form']['working']);
        unset($values['product_form']['working']); // an unchecked box isn't sent
        $values['product_form']['notWorkingReason'] = '  ремонт подсветки ';
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $product = $this->em->find(Product::class, $this->broken->getId());
        self::assertFalse($product->isWorking());
        self::assertSame('ремонт подсветки', $product->getNotWorkingReason());
        self::assertNotNull($this->em->find(Booking::class, $booking->getId()));

        // the list marks it and filters by it
        $crawler = $this->client->request('GET', '/admin/products');
        $row = $crawler->filter('tbody tr')->reduce(static fn ($tr) => str_contains($tr->text(), 'Щит на Мира'));
        self::assertSame('ремонт подсветки', $row->filter('span[title]:contains("Не работает")')->attr('title'));
        self::assertCount(0, $crawler->filter('tbody tr:contains("Щит на Ленина") span[title]:contains("Не работает")'));
        $names = fn (string $query) => $this->client->request('GET', '/admin/products'.$query)->filter('tbody tr th')->each(static fn ($th) => $th->filter('span.block')->first()->text());
        self::assertSame(['Щит на Мира'], $names('?working=no'));
        self::assertSame(['Щит на Ленина'], $names('?working=yes'));

        // working again: the reason goes
        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('ремонт подсветки', $values['product_form']['notWorkingReason']);
        $values['product_form']['working'] = '1';
        $this->submit($uri, $values);
        $this->em->clear();
        $product = $this->em->find(Product::class, $this->broken->getId());
        self::assertTrue($product->isWorking());
        self::assertNull($product->getNotWorkingReason());
    }

    public function testLeftOutOfTheDashboard(): void
    {
        $this->markBroken();

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        // two sides of the working billboard, none of the broken one
        self::assertStringContainsString('2 стороны', preg_replace('/\s+/', ' ', $crawler->filter('#dashboard-billboards')->text()));
    }

    public function testTakesNoNewBookings(): void
    {
        $this->markBroken();

        $crawler = $this->client->request('GET', '/admin/products/'.$this->broken->getId().'/bookings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=status]', 'Конструкция не работает: демонтаж');
        self::assertCount(0, $crawler->filter('form[name="booking_form"]'));

        try {
            $this->hold($this->broken);
            self::fail('A structure out of order took a booking');
        } catch (BookingException $e) {
            self::assertSame('Конструкция «Щит на Мира» не работает (демонтаж) — новые брони на неё не принимаются.', $e->getMessage());
        }
    }

    public function testNotOnTheWebsite(): void
    {
        $this->markBroken();

        $this->client->request('GET', '/api/v1/structures');
        $items = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['items'];
        self::assertSame(['Щит на Ленина'], array_column($items, 'name'));

        $this->client->request('GET', '/api/v1/structures/'.$this->broken->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/structures/'.$this->broken->getId().'/availability');
        self::assertResponseStatusCodeSame(404);

        $side = $this->broken->getSides()->first();
        $this->client->request('POST', '/api/v1/orders', content: json_encode(['contactName' => 'Иван', 'phone' => '+7 900 111-22-33', 'agree' => true, 'items' => [['sideId' => $side->getId(), 'from' => '2026-10-01', 'to' => '2026-10-31']]], \JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_sides', json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['error']);
    }

    public function testNotOfferedInMediaPlans(): void
    {
        $plan = (new MediaPlan())->setTitle('Осень')->setClientName('Кафе')->setClient($this->createClientCard())
            ->setStartMonth(new \DateTimeImmutable('2026-10-01'))->setMonths(1);
        $this->em->persist($plan);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'?q=Щит');
        self::assertCount(4, $crawler->filter('#plan-picker form')); // two sides of each
        $token = $crawler->filter('#plan-picker form input[name="_token"]')->attr('value');

        $this->markBroken();
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'?q=Щит');
        self::assertCount(2, $crawler->filter('#plan-picker form'));
        self::assertStringNotContainsString('Щит на Мира', $crawler->filter('#plan-picker')->text());

        // e.g. from a map popup opened before
        $this->client->request('POST', '/admin/media-plans/'.$plan->getId().'/items', ['_token' => $token, 'sides' => [$this->broken->getSides()->first()->getId()]]);
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=alert]', 'Не работает, в медиаплан не добавлено: Щит на Мира');
        self::assertCount(0, $this->em->find(MediaPlan::class, $plan->getId())->getItems());

        // nor on the map
        $this->client->request('GET', '/admin/map/data');
        $points = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['points'];
        self::assertSame(['Щит на Ленина'], array_column($points, 'name'));
    }

    private function markBroken(): void
    {
        $this->broken->setWorking(false)->setNotWorkingReason('демонтаж');
        $this->em->flush();
    }

    private function hold(Product $product): Booking
    {
        $request = new BookingRequest();
        $request->side = $product->getSides()->first();
        $request->startMonth = '2026-10';
        $request->client = $this->createClientCard('ООО Ромашка '.uniqid(), uniqid().'@romashka.ru');
        $request->clientName = 'Иван';
        $request->clientPhone = '+7 900 000-00-00';

        return static::getContainer()->get(BookingManager::class)->hold($request);
    }

    private function product(string $name, Category $category, ProductType $type, District $district): Product
    {
        $product = (new Product())->setName($name)->setCategory($category)->setProductType($type)
            ->setDistrict($district)->setPrice('30000')->setLatitude('55.0000000')->setLongitude('37.0000000');
        foreach (['A', 'B'] as $side) {
            $product->addSide((new ProductSide())->setName($side));
        }

        return $product;
    }
}
