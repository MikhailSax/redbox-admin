<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Service\BookingManager;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class BookingControllerTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private MockClock $clock;
    private Product $billboard;
    private Product $screen;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = self::mockTime('2026-09-10 12:00:00');
        $this->customer = $this->createClientCard();

        $category = (new Category())->setName('Билборд 6х3');
        $district = (new District())->setName('Центральный');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $this->billboard = $this->product('Щит на Ленина', $category, $static, $district, ['A', 'B']);
        $this->screen = $this->product('Экран на площади', $category, $video, $district, ['A']);

        foreach ([$category, $district, $static, $video, $this->billboard, $this->screen] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testProductPageShowsGridAndCreatesHold(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Занятость на 12 месяцев');
        self::assertCount(2, $crawler->filter('tbody')->first()->filter('tr'));
        self::assertSelectorNotExists('input[name="booking_form[slots]"]');

        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'B')->getId();
        $values['booking_form']['startMonth'] = '2026-10';
        $values['booking_form']['months'] = '2';
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $this->submit($uri, $values);

        self::assertResponseRedirects($this->url($this->billboard));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'ждёт оплаты до 11.09.2026 12:00');
        self::assertSelectorTextContains('main', 'ООО Ромашка');
        // the client card is linked, not just named
        self::assertSelectorExists('tbody a[href="/admin/clients/'.$this->customer->getId().'"]');

        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertSame('B', $booking->getSide()->getName());
        self::assertSame(2, $booking->getMonthCount());
        self::assertSame($this->customer->getId(), $booking->getClient()?->getId());
        // contact fields left empty are taken from the client card
        self::assertSame('Иван Петров', $booking->getClientName());
        self::assertSame('+7 900 111-22-33', $booking->getClientPhone());
        self::assertSame('me@redbox.local', $booking->getCreatedBy()?->getEmail());
    }

    public function testBookingWithoutAClientCardIsRefused(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'A')->getId();
        $values['booking_form']['startMonth'] = '2026-10';
        $values['booking_form']['client'] = '';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#booking_form_client_error1', 'Выберите клиента');
        self::assertSame(0, $this->em->getRepository(Booking::class)->count([]));
    }

    public function testAccountWithAnUnconfirmedEmailIsNotOfferedAsAClient(): void
    {
        $stranger = $this->createClientCard('ООО «Аноним»', 'stranger@example.com')->setEmailVerifiedAt(null);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->billboard));
        $options = $crawler->filter('select[name="booking_form[client]"] option')->each(static fn ($o) => $o->attr('value'));
        self::assertContains((string) $this->customer->getId(), $options);
        self::assertNotContains((string) $stranger->getId(), $options);
    }

    public function testClientCardListsTheStructuresTheyHold(): void
    {
        $this->hold($this->side($this->billboard, 'A'), '2026-09');

        $crawler = $this->client->request('GET', '/admin/clients/'.$this->customer->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=tablist] a[data-tab="bookings"]', 'Брони 1');
        $bookings = $crawler->filter('#tab-bookings');
        self::assertStringContainsString('Щит на Ленина', $bookings->text());
        self::assertStringContainsString('Сентябрь 2026', $bookings->text());
        self::assertSame('/admin/products/'.$this->billboard->getId().'/bookings', $bookings->filter('tbody a')->attr('href'));
    }

    public function testTakenSideShowsError(): void
    {
        $this->hold($this->side($this->billboard, 'A'), '2026-09');

        $crawler = $this->client->request('GET', $this->url($this->billboard));
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'A')->getId();
        $values['booking_form']['startMonth'] = '2026-09';
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $values['booking_form']['clientName'] = 'Второй';
        $values['booking_form']['clientPhone'] = '123';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'Сторона A уже забронирована: Сентябрь 2026');
        self::assertSame(1, $this->em->getRepository(Booking::class)->count([]));
    }

    public function testAirtimeIsBookedByDays(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->screen));
        self::assertSelectorTextContains('h2', 'Занятость эфира на 42 дней');
        self::assertSame('1', $crawler->filter('input[name="booking_form[slots]"]')->attr('value'));
        self::assertSelectorNotExists('select[name="booking_form[startMonth]"]');
        // dates start as the shortest placement from today: two weeks
        self::assertSame('2026-09-10', $crawler->filter('input[name="booking_form[startDate]"]')->attr('value'));
        self::assertSame('2026-09-23', $crawler->filter('input[name="booking_form[endDate]"]')->attr('value'));

        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['startDate'] = '2026-09-12';
        $values['booking_form']['endDate'] = '2026-09-11';
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $values['booking_form']['clientName'] = 'Кафе';
        $values['booking_form']['clientPhone'] = '123';
        $values['booking_form']['slots'] = '13';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Слотов — от 1 до 12');
        self::assertSelectorTextContains('main', 'Последний день не может быть раньше первого');

        $values['booking_form']['slots'] = '2';
        $values['booking_form']['endDate'] = '2026-09-18';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Минимальное размещение — 14 дней');

        $values['booking_form']['endDate'] = '2026-09-25';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertSame(14, $booking->getDays());
        self::assertSame(2, $booking->getSlots());
        self::assertSelectorTextContains('main', '12–25 сентября 2026');
        self::assertSelectorTextContains('main', '14 дн. · 2 слота');
        // the grid counts the slots on each booked day only
        $cells = $crawler->filter('tbody')->first()->filter('tr td');
        self::assertSame('0/12', trim($cells->eq(0)->text())); // 10th
        self::assertSame('2/12', trim($cells->eq(2)->text())); // 12th
        self::assertStringStartsWith('Занято слотов: 2 из 12', $cells->eq(2)->filter('div')->attr('title'));
        self::assertSame('0/12', trim($cells->eq(16)->text())); // 26th
    }

    public function testAirtimeCannotStartInThePast(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->screen));
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['startDate'] = '2026-09-01';
        $values['booking_form']['endDate'] = '2026-09-20';
        $values['booking_form']['slots'] = '1';
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $values['booking_form']['clientName'] = 'Кафе';
        $values['booking_form']['clientPhone'] = '123';
        $this->submit($uri, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'Первый день брони уже прошёл');
    }

    public function testSidesOfOneStructureAreSoldByTheirOwnType(): void
    {
        // a static structure with a video screen on side B
        $sideA = $this->side($this->billboard, 'A');
        $sideB = $this->side($this->billboard, 'B')->setProductType($this->em->getRepository(ProductType::class)->findOneBy(['name' => 'Видеоэкран']));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertResponseIsSuccessful();
        // a days grid for the screen side, a months grid for the static one
        $grids = $crawler->filter('table tbody');
        self::assertSame('Занятость эфира на 42 дней', $crawler->filter('h2')->eq(0)->text());
        self::assertSame(['B 12 × 5 сек'], $grids->eq(0)->filter('th')->each(static fn ($th) => $th->text()));
        self::assertSame('Занятость на 12 месяцев', $crawler->filter('h2')->eq(1)->text());
        self::assertSame(['A'], $grids->eq(1)->filter('th')->each(static fn ($th) => $th->text()));
        // both sets of fields; the side options tell the page which set to show
        self::assertCount(1, $crawler->filter('input[name="booking_form[slots]"]'));
        self::assertCount(1, $crawler->filter('select[name="booking_form[startMonth]"]'));
        self::assertSame('airtime', $crawler->filter('option[value="'.$sideB->getId().'"]')->attr('data-booking-mode'));
        self::assertSame('Сторона B · 12 слотов по 5 сек', $crawler->filter('option[value="'.$sideB->getId().'"]')->text());
        self::assertSame('side', $crawler->filter('option[value="'.$sideA->getId().'"]')->attr('data-booking-mode'));

        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $values['booking_form']['clientName'] = 'Кафе';
        $values['booking_form']['clientPhone'] = '123';

        // the screen side takes slots for some days, not the whole side
        $values['booking_form']['side'] = (string) $sideB->getId();
        $values['booking_form']['startDate'] = '2026-09-12';
        $values['booking_form']['endDate'] = '2026-09-25';
        $values['booking_form']['slots'] = '3';
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        // another client's slots still fit in the same days
        $values['booking_form']['slots'] = '2';
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        // the static side is booked by months: the dates and the slots of the form don't apply to it
        $values['booking_form']['side'] = (string) $sideA->getId();
        $values['booking_form']['startMonth'] = '2026-10';
        $values['booking_form']['months'] = '1';
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        $bookings = $this->em->getRepository(Booking::class)->findBy([], ['id' => 'ASC']);
        self::assertSame([3, 2, null], array_map(static fn (Booking $b) => $b->getSlots(), $bookings));
        self::assertSame('2026-09-12', $bookings[0]->getStartDate()->format('Y-m-d'));
        self::assertSame(['2026-10-01', '2026-10-31'], [$bookings[2]->getStartDate()->format('Y-m-d'), $bookings[2]->getEndDate()->format('Y-m-d')]);

        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertSame('5/12', trim($crawler->filter('table tbody')->eq(0)->filter('td')->eq(2)->text())); // 3 + 2 slots on the 12th
    }

    public function testScreenSlotsAreShown(): void
    {
        $request = new BookingRequest();
        $request->side = $this->side($this->screen, 'A');
        $request->startMonth = '2026-09';
        $request->slots = 3;
        $request->client = $this->customer;
        $request->clientName = 'Кафе';
        $request->clientPhone = '123';
        static::getContainer()->get(BookingManager::class)->hold($request);
        $this->hold($this->side($this->billboard, 'A'), '2026-09');

        // the list: the screen's side chip shows the slots taken, a whole side doesn't
        $crawler = $this->client->request('GET', '/admin/products');
        $screenRow = $crawler->filter('tbody tr')->reduce(static fn ($row) => str_contains($row->text(), 'Экран на площади'));
        self::assertSame('A3/12', trim($screenRow->filter('span[title^="Сторона A"]')->text())); // side name, then the slots (spaced by CSS)
        self::assertStringContainsString('занято слотов: 3 из 12', $screenRow->filter('span[title^="Сторона A"]')->attr('title'));
        $billboardRow = $crawler->filter('tbody tr')->reduce(static fn ($row) => str_contains($row->text(), 'Щит на Ленина'));
        self::assertStringNotContainsString('/', $billboardRow->filter('span[title^="Сторона A"]')->text());

        // the structure card: slots and a progress bar
        $this->client->request('GET', '/admin/products/'.$this->screen->getId().'/edit');
        self::assertSelectorTextContains('#product-status', 'занято слотов: 3 из 12');
        self::assertSelectorExists('#product-status [role=progressbar][aria-valuenow="25"]');
    }

    public function testPayFromProductPage(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-09');

        $crawler = $this->client->request('GET', $this->url($this->billboard));
        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$booking->getId().'/pay"]');

        self::assertResponseRedirects($this->url($this->billboard));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Оплата отмечена');
        self::assertSame(BookingStatus::Paid, $this->reload($booking)->getStatus());
    }

    public function testCancelFromListReturnsToList(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-09');

        $crawler = $this->client->request('GET', '/admin/bookings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Щит на Ленина');
        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$booking->getId().'/cancel"]');

        self::assertResponseRedirects('/admin/bookings');
        self::assertSame(BookingStatus::Cancelled, $this->reload($booking)->getStatus());
    }

    public function testListFiltersByStatusAndShowsOverdueHoldAsExpired(): void
    {
        $this->hold($this->side($this->billboard, 'A'), '2026-09', 'Просрочил');
        $paid = $this->hold($this->side($this->billboard, 'B'), '2026-09', 'Заплатил');
        static::getContainer()->get(BookingManager::class)->markPaid($paid);
        $this->clock->modify('+25 hours');

        $crawler = $this->client->request('GET', '/admin/bookings');
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Истекла', $crawler->filter('tbody tr:contains("Просрочил")')->text());
        self::assertCount(0, $crawler->filter('tbody tr:contains("Просрочил") form'));

        $crawler = $this->client->request('GET', '/admin/bookings?status=paid');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Заплатил');
    }

    public function testExpireCommand(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-09');
        $this->clock->modify('+25 hours');

        $tester = new CommandTester((new Application(static::$kernel))->find('app:booking:expire'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Снято просроченных броней: 1', $tester->getDisplay());
        self::assertSame(BookingStatus::Expired, $this->reload($booking)->getStatus());
    }

    public function testCannotDeleteProductOrSideWithActiveBooking(): void
    {
        $this->hold($this->side($this->billboard, 'B'), '2026-09');

        // Delete product
        $crawler = $this->client->request('GET', '/admin/products');
        $this->submitPostForm($crawler, 'form[action="/admin/products/'.$this->billboard->getId().'/delete"]');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'есть действующие брони (1)');

        // Remove side B in the form
        $crawler = $this->client->request('GET', '/admin/products/'.$this->billboard->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        unset($values['product_form']['sides'][1]);
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product-form', 'Сторону B нельзя удалить: на неё есть действующие брони');

        $this->em->clear();
        self::assertCount(2, $this->em->find(Product::class, $this->billboard->getId())->getSides());
    }

    public function testProductListShowsStatusAndFiltersByIt(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $manager->markPaid($this->hold($this->side($this->billboard, 'A'), '2026-09'));
        $manager->markPaid($this->hold($this->side($this->billboard, 'B'), '2026-09'));

        $crawler = $this->client->request('GET', '/admin/products');
        self::assertResponseIsSuccessful();
        self::assertSame('occupied', $crawler->filter('tbody tr:contains("Щит на Ленина") [data-status]')->attr('data-status'));
        self::assertSame('free', $crawler->filter('tbody tr:contains("Экран на площади") [data-status]')->attr('data-status'));
        self::assertSelectorTextContains('main', 'Заняты 1');
        self::assertSelectorTextContains('main', 'Свободны 1');

        $crawler = $this->client->request('GET', '/admin/products?status=occupied');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Щит на Ленина');

        // Status for another month
        $crawler = $this->client->request('GET', '/admin/products?status=free&month=2026-10');
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('main', 'статус на октябрь 2026');
    }

    public function testProductCardTabs(): void
    {
        $this->hold($this->side($this->billboard, 'A'), '2026-09');
        $url = '/admin/products/'.$this->billboard->getId().'/edit';

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('[data-tab-panel]'));
        self::assertSame(['main'], $crawler->filter('[data-tab-panel]:not(.hidden)')->each(fn ($p) => $p->attr('data-tab-panel')));
        self::assertSelectorTextContains('[role=tablist]', 'Бронирование 1'); // active bookings badge
        // Status card: side A on hold, B free → structure is free (B can be sold)
        self::assertSelectorTextContains('h1 + span', 'Свободна');
        self::assertSelectorTextContains('#product-status', 'Забронирована');

        // An error on the "Расположение" tab opens it and marks it
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['product_form']['latitude'] = '100';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        self::assertSame(['location'], $crawler->filter('[data-tab-panel]:not(.hidden)')->each(fn ($p) => $p->attr('data-tab-panel')));
        self::assertSame(['location'], $crawler->filter('[data-tab-error]')->each(fn ($t) => $t->attr('data-tab')));

        // Saving from the SEO tab returns to it
        $values['product_form']['latitude'] = '55.1';
        $values['product_form']['seoTitle'] = 'Билборд в центре';
        $values['_tab'] = 'seo';
        $this->submit($uri, $values);
        self::assertResponseRedirects($url.'#seo');
    }

    public function testSuperManagerCanBook(): void
    {
        $this->client->loginUser($this->createUser('manager@redbox.local', User::ROLE_SUPER_MANAGER));

        $this->client->request('GET', $this->url($this->billboard));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/bookings');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $sides
     */
    private function product(string $name, Category $category, ProductType $type, District $district, array $sides): Product
    {
        $product = (new Product())->setName($name)->setCategory($category)->setProductType($type)
            ->setDistrict($district)->setPrice('30000')->setLatitude('55.0000000')->setLongitude('37.0000000');
        foreach ($sides as $side) {
            $product->addSide((new ProductSide())->setName($side));
        }

        return $product;
    }

    private function side(Product $product, string $name): ProductSide
    {
        return $product->getSides()->filter(fn (ProductSide $s) => $s->getName() === $name)->first();
    }

    private function hold(ProductSide $side, string $month, string $client = 'ООО Ромашка'): Booking
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startMonth = $month;
        $request->client = $this->customer;
        $request->clientName = $client;
        $request->clientPhone = '+7 900 000-00-00';

        return static::getContainer()->get(BookingManager::class)->hold($request);
    }

    private function reload(Booking $booking): Booking
    {
        $this->em->clear();

        return $this->em->find(Booking::class, $booking->getId());
    }

    private function url(Product $product): string
    {
        return '/admin/products/'.$product->getId().'/bookings';
    }
}
