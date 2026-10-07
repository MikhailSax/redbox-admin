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
 * A side marked as not working: kept in the CRM, left out of the dashboard, the website, media plans and new bookings.
 * A structure with no working side is left out altogether.
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

    public function testMarkedOnTheSideWithAReason(): void
    {
        // a booking made while it worked stays
        $booking = $this->hold($this->side($this->broken, 'B'));

        $crawler = $this->client->request('GET', '/admin/products/'.$this->broken->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('1', $values['product_form']['sides'][1]['working']);
        unset($values['product_form']['sides'][1]['working']); // an unchecked box isn't sent
        $values['product_form']['sides'][1]['notWorkingReason'] = '  ремонт подсветки ';
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $product = $this->em->find(Product::class, $this->broken->getId());
        self::assertTrue($this->side($product, 'A')->isWorking());
        self::assertFalse($this->side($product, 'B')->isWorking());
        self::assertSame('ремонт подсветки', $this->side($product, 'B')->getNotWorkingReason());
        self::assertTrue($product->isWorking()); // side A is still for sale
        self::assertNotNull($this->em->find(Booking::class, $booking->getId()));

        // the list marks the side and filters by it
        $crawler = $this->client->request('GET', '/admin/products');
        $row = $crawler->filter('tbody tr')->reduce(static fn ($tr) => str_contains($tr->text(), 'Щит на Мира'));
        self::assertSame('Не работает: B', trim($row->filter('span[title]:contains("Не работает")')->text()));
        self::assertSame('Сторона B: ремонт подсветки', $row->filter('span[title]:contains("Не работает")')->attr('title'));
        self::assertStringContainsString('line-through', $row->filter('td span[title^="Сторона B"]')->attr('class'));
        self::assertStringNotContainsString('line-through', $row->filter('td [title^="Сторона A"]')->attr('class'));
        self::assertCount(0, $crawler->filter('tbody tr:contains("Щит на Ленина") span[title]:contains("Не работает")'));
        $names = fn (string $query) => $this->client->request('GET', '/admin/products'.$query)->filter('tbody tr th')->each(static fn ($th) => $th->filter('span.block')->first()->text());
        self::assertSame(['Щит на Мира'], $names('?working=no'));
        self::assertSame(['Щит на Ленина'], $names('?working=yes'));

        // working again: the reason goes
        $crawler = $this->client->request('GET', '/admin/products/'.$product->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('ремонт подсветки', $values['product_form']['sides'][1]['notWorkingReason']);
        $values['product_form']['sides'][1]['working'] = '1';
        $this->submit($uri, $values);
        $this->em->clear();
        $side = $this->side($this->em->find(Product::class, $this->broken->getId()), 'B');
        self::assertTrue($side->isWorking());
        self::assertNull($side->getNotWorkingReason());
    }

    public function testLeftOutOfTheDashboard(): void
    {
        $this->markBroken('B');

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        // two sides of the working billboard and side A of the other one
        self::assertStringContainsString('3 стороны', preg_replace('/\s+/', ' ', $crawler->filter('#dashboard-billboards')->text()));
    }

    public function testTakesNoNewBookings(): void
    {
        $this->markBroken('B');

        $crawler = $this->client->request('GET', '/admin/products/'.$this->broken->getId().'/bookings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=status]', 'Не работает: сторона B (демонтаж)');
        // only side A is offered: picked already
        self::assertSame([(string) $this->side($this->broken, 'A')->getId()], $crawler->filter('select[name="booking_form[side]"] option')->each(static fn ($o) => $o->attr('value')));
        self::assertSelectorTextContains('table tbody', 'не работает');

        try {
            $this->hold($this->side($this->broken, 'B'));
            self::fail('A side out of order took a booking');
        } catch (BookingException $e) {
            self::assertSame('Не работает: Щит на Мира, сторона B (демонтаж) — новые брони на эту сторону не принимаются.', $e->getMessage());
        }
        $this->hold($this->side($this->broken, 'A'));

        // no side works: no booking form at all
        $this->markBroken('A');
        $crawler = $this->client->request('GET', '/admin/products/'.$this->broken->getId().'/bookings');
        self::assertCount(0, $crawler->filter('form[name="booking_form"]'));
    }

    public function testNotOnTheWebsite(): void
    {
        $this->markBroken('B');

        // the structure is there with side A only
        $items = $this->json('/api/v1/structures')['items'];
        self::assertEqualsCanonicalizing(['Щит на Ленина', 'Щит на Мира'], array_column($items, 'name'));
        self::assertSame(['A'], array_column($this->json('/api/v1/structures/'.$this->broken->getId())['sides'], 'name'));
        self::assertSame(['A'], array_column($this->json('/api/v1/structures/'.$this->broken->getId().'/availability')['sides'], 'name'));

        $this->client->request('POST', '/api/v1/orders', content: json_encode(['contactName' => 'Иван', 'phone' => '+7 900 111-22-33', 'agree' => true, 'items' => [['sideId' => $this->side($this->broken, 'B')->getId(), 'from' => '2026-10-01', 'to' => '2026-10-31']]], \JSON_THROW_ON_ERROR), server: ['CONTENT_TYPE' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unknown_sides', json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['error']);

        // no side works: the structure is gone
        $this->markBroken('A');
        self::assertSame(['Щит на Ленина'], array_column($this->json('/api/v1/structures')['items'], 'name'));
        $this->client->request('GET', '/api/v1/structures/'.$this->broken->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/structures/'.$this->broken->getId().'/availability');
        self::assertResponseStatusCodeSame(404);
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

        $this->markBroken('B');
        $crawler = $this->client->request('GET', '/admin/media-plans/'.$plan->getId().'?q=Щит');
        self::assertCount(3, $crawler->filter('#plan-picker form'));
        self::assertCount(0, $crawler->filter('#plan-picker input[name="sides[]"][value="'.$this->side($this->broken, 'B')->getId().'"]'));

        // e.g. from a map popup opened before
        $this->client->request('POST', '/admin/media-plans/'.$plan->getId().'/items', ['_token' => $token, 'sides' => [$this->side($this->broken, 'B')->getId()]]);
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=alert]', 'Не работает, в медиаплан не добавлено: Щит на Мира, сторона B (демонтаж)');
        self::assertCount(0, $this->em->find(MediaPlan::class, $plan->getId())->getItems());

        // the map: side B is not there; a structure with no working side is not either
        $sides = fn () => array_column(array_merge(...array_column($this->json('/admin/map/data')['points'], 'sides')), 'name');
        self::assertEqualsCanonicalizing(['A', 'A', 'B'], $sides());
        $this->markBroken('A');
        self::assertSame(['Щит на Ленина'], array_column($this->json('/admin/map/data')['points'], 'name'));
    }

    private function markBroken(string $side): void
    {
        // found anew: a request in between resets the entity manager
        $this->em->find(ProductSide::class, $this->side($this->broken, $side)->getId())->setWorking(false)->setNotWorkingReason('демонтаж');
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function hold(ProductSide $side): Booking
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startMonth = '2026-10';
        $request->client = $this->createClientCard('ООО Ромашка '.uniqid(), uniqid().'@romashka.ru');
        $request->clientName = 'Иван';
        $request->clientPhone = '+7 900 000-00-00';

        return static::getContainer()->get(BookingManager::class)->hold($request);
    }

    private function side(Product $product, string $name): ProductSide
    {
        return $product->getSides()->findFirst(static fn (int|string $i, ProductSide $s) => $s->getName() === $name);
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
