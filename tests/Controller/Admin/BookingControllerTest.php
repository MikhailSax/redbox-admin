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
use App\Enum\ClientType;
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
        self::assertSelectorTextContains('[role=alert]', 'ждёт подтверждения до 11.09.2026 12:00');
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

    public function testBookingForANewClient(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'A')->getId();
        $values['booking_form']['startMonth'] = '2026-10';
        $values['booking_form']['client'] = '';

        // neither a client from the list nor a new one
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Выберите клиента или впишите нового');

        $values['booking_form']['newClient'] = 'Носкова Виктория Анатольевна';
        $values['booking_form']['newClientPhone'] = '89240000000';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=alert]', 'Клиент «Носкова Виктория Анатольевна» добавлен в базу клиентов');

        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertSame('Носкова Виктория Анатольевна', $booking->getClient()->getName());
        self::assertSame(ClientType::Individual, $booking->getClient()->getClientType());
        self::assertSame('89240000000', $booking->getClientPhone()); // the contact falls back to the new card
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
        self::assertSelectorTextContains('[role=alert]', 'Оплата отмечена, бронь подтверждена');
        self::assertSame(BookingStatus::Confirmed, $this->reload($booking)->getStatus());
        self::assertTrue($this->reload($booking)->isPaid());
    }

    /** The bookings list goes by month: the current month opens first, a booking shows on every month it takes */
    public function testBookingsAreShownByMonthTabs(): void
    {
        $this->hold($this->side($this->billboard, 'A'), '2026-09', 'Сентябрьский');
        $november = $this->hold($this->side($this->billboard, 'B'), '2026-11', 'Ноябрьский');

        $crawler = $this->client->request('GET', $this->url($this->billboard));
        $tabs = $crawler->filter('nav[aria-label="Брони по месяцам"] a');
        self::assertSame(['Все 2', 'Сентябрь 2026 1', 'Ноябрь 2026 1'], $tabs->each(fn ($a) => preg_replace('/\s+/', ' ', trim($a->text()))));
        self::assertSame('Сентябрь 2026 1', preg_replace('/\s+/', ' ', trim($crawler->filter('nav[aria-label="Брони по месяцам"] a[aria-current="page"]')->text())));
        $list = $crawler->filter('.table-card')->last();
        self::assertStringContainsString('Сентябрьский', $list->text());
        self::assertStringNotContainsString('Ноябрьский', $list->text());

        $crawler = $this->client->request('GET', $this->url($this->billboard).'?month=2026-11');
        $list = $crawler->filter('.table-card')->last();
        self::assertStringContainsString('Ноябрьский', $list->text());
        self::assertStringNotContainsString('Сентябрьский', $list->text());

        // a month without bookings is not a tab: the current month opens instead
        $crawler = $this->client->request('GET', $this->url($this->billboard).'?month=2026-10');
        self::assertStringContainsString('Сентябрьский', $crawler->filter('.table-card')->last()->text());

        $crawler = $this->client->request('GET', $this->url($this->billboard).'?month=all');
        $list = $crawler->filter('.table-card')->last();
        self::assertStringContainsString('Сентябрьский', $list->text());
        self::assertStringContainsString('Ноябрьский', $list->text());

        // an action brings back to the tab it was pressed on
        $crawler = $this->client->request('GET', $this->url($this->billboard).'?month=2026-11');
        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$november->getId().'/confirm"]');
        self::assertResponseRedirects($this->url($this->billboard).'?month=2026-11');
    }

    /** Post-paying clients: the booking is confirmed first, the payment marked when the money comes */
    public function testConfirmThenPayThenUnpay(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-09', 'Постоплатник');

        $crawler = $this->client->request('GET', '/admin/bookings');
        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$booking->getId().'/confirm"]');
        self::assertResponseRedirects('/admin/bookings');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Бронь подтверждена');
        self::assertSame(BookingStatus::Confirmed, $this->reload($booking)->getStatus());
        self::assertFalse($this->reload($booking)->isPaid());
        $row = $crawler->filter('tbody tr:contains("Постоплатник")');
        self::assertStringContainsString('Подтверждена', $row->text());
        self::assertStringContainsString('Не оплачена', $row->text());
        self::assertCount(0, $row->filter('form[action$="/confirm"]'));

        // the "confirmed, not paid" filter finds it
        $crawler = $this->client->request('GET', '/admin/bookings?status=unpaid');
        self::assertCount(1, $crawler->filter('tbody tr'));

        // still holding the side days later
        $this->clock->modify('+3 days');
        $crawler = $this->client->request('GET', '/admin/bookings');
        self::assertStringContainsString('Подтверждена', $crawler->filter('tbody tr:contains("Постоплатник")')->text());

        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$booking->getId().'/pay"]');
        $crawler = $this->client->followRedirect();
        self::assertTrue($this->reload($booking)->isPaid());
        self::assertStringContainsString('Оплачена', $crawler->filter('tbody tr:contains("Постоплатник")')->text());
        $this->client->request('GET', '/admin/bookings?status=unpaid');
        self::assertSelectorTextContains('tbody', 'Ничего не найдено');

        $crawler = $this->client->request('GET', '/admin/bookings');
        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$booking->getId().'/unpay"]');
        $this->client->followRedirect();
        self::assertFalse($this->reload($booking)->isPaid());
        self::assertSame(BookingStatus::Confirmed, $this->reload($booking)->getStatus());
    }

    public function testScreenBookingByHalfSlots(): void
    {
        $screenSide = $this->side($this->screen, 'A');
        $screenSide->setSlotSeconds(10)->setSlotCount(1);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->screen));
        self::assertSelectorExists('select[name="booking_form[slotSeconds]"] option[value="5"]');

        foreach (['Первая половина', 'Вторая половина'] as $title) {
            $crawler = $this->client->request('GET', $this->url($this->screen));
            [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
            $values['booking_form']['side'] = (string) $screenSide->getId();
            $values['booking_form']['startDate'] = '2026-09-10';
            $values['booking_form']['endDate'] = '2026-09-30';
            $values['booking_form']['slots'] = '1';
            $values['booking_form']['slotSeconds'] = '5';
            $values['booking_form']['client'] = (string) $this->customer->getId();
            $values['booking_form']['comment'] = $title;
            $this->submit($uri, $values);
            self::assertResponseRedirects($this->url($this->screen));
        }

        // the 10-second slot is sold out by two halves: the grid shows it full, a third half doesn't fit
        $crawler = $this->client->request('GET', $this->url($this->screen));
        self::assertStringContainsString('1/1', $crawler->filter('tbody')->first()->text());
        self::assertStringContainsString('1 слот по 5 сек', $crawler->filter('main')->text());
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $screenSide->getId();
        $values['booking_form']['startDate'] = '2026-09-10';
        $values['booking_form']['endDate'] = '2026-09-30';
        $values['booking_form']['slots'] = '1';
        $values['booking_form']['slotSeconds'] = '5';
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'свободно слотов: 0 из 1');
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

        $crawler = $this->client->request('GET', '/admin/bookings?status=confirmed');
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
    public function testListShowsTheSideApartAndSortsByAnyColumn(): void
    {
        $this->hold($this->side($this->billboard, 'B'), '2026-10', 'Борис');
        $this->hold($this->side($this->billboard, 'A'), '2026-11', 'Анна');
        $this->hold($this->side($this->screen, 'A'), '2026-09', 'Вера');
        $rows = fn (string $query): array => $this->client->request('GET', '/admin/bookings'.$query)->filter('tbody tr')
            ->each(static fn ($row) => trim($row->filter('th')->text()).' '.trim($row->filter('td')->eq(0)->text()));

        // newest first; the side in a column of its own
        self::assertSame(['Экран на площади A', 'Щит на Ленина A', 'Щит на Ленина B'], $rows(''));
        self::assertResponseIsSuccessful();
        // by structure, its sides one after another
        self::assertSame(['Щит на Ленина A', 'Щит на Ленина B', 'Экран на площади A'], $rows('?sort=product'));
        self::assertSame(['Экран на площади A', 'Щит на Ленина A', 'Щит на Ленина B'], $rows('?sort=product&dir=desc')); // sides still A to B
        self::assertSame(['Экран на площади A', 'Щит на Ленина B', 'Щит на Ленина A'], $rows('?sort=period'));
        self::assertSame(['Щит на Ленина B', 'Щит на Ленина A', 'Экран на площади A'], $rows('?sort=nonsense&dir=asc')); // unknown column: by creation date

        // the header links: the active column turns its order around, the status filter stays
        $crawler = $this->client->request('GET', '/admin/bookings?status=hold&sort=product');
        self::assertSame('ascending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));
        self::assertSame('/admin/bookings?status=hold&sort=product&dir=desc', $crawler->filter('thead a:contains("Конструкция")')->attr('href'));
        self::assertSame('/admin/bookings?status=hold&sort=side', $crawler->filter('thead a:contains("Сторона")')->attr('href'));
        self::assertSame('/admin/bookings?status=hold', $crawler->filter('thead a:contains("Создана")')->attr('href'));
        self::assertSame('product', $crawler->filter('form[data-live-filter] input[name="sort"]')->attr('value'));
    }

    public function testNewClientIsAPersonAnEntrepreneurOrACompanyAsChosen(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertCount(3, $crawler->filter('input[name="booking_form[newClientType]"]'));
        self::assertCount(0, $crawler->filter('input[name="booking_form[newClientType]"][checked]'));
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'A')->getId();
        $values['booking_form']['startMonth'] = '2026-10';
        $values['booking_form']['client'] = '';
        $values['booking_form']['newClient'] = 'ИП Сидоров С. С.';

        // an entrepreneur needs an ИНН of 12 digits
        $values['booking_form']['newClientType'] = ClientType::Entrepreneur->value;
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Укажите ИНН');
        $values['booking_form']['newClientInn'] = '1234567890';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'ИНН ИП — 12 цифр');

        $values['booking_form']['newClientInn'] = '123456789012';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $client = $this->em->getRepository(Booking::class)->findOneBy([])->getClient();
        self::assertSame(ClientType::Entrepreneur, $client->getClientType());
        self::assertSame(['ИП Сидоров С. С.', '123456789012'], [$client->getCompany(), $client->getInn()]);

        // a private person chosen by hand keeps no requisites
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'B')->getId();
        $values['booking_form']['newClient'] = 'Петрова Анна';
        $values['booking_form']['newClientType'] = ClientType::Individual->value;
        $values['booking_form']['newClientInn'] = '';
        $this->submit($uri, $values);
        self::assertResponseRedirects();
        $client = $this->em->getRepository(User::class)->findOneBy(['name' => 'Петрова Анна']);
        self::assertSame(ClientType::Individual, $client->getClientType());
        self::assertNull($client->getCompany());
    }

    public function testScreenSellsPartOfItsBlock(): void
    {
        // 12 slots in the block, 9 of them on sale
        $side = $this->side($this->screen, 'A')->setSlotCount(9)->setLoopSlotCount(12)->setDailyOts(12000);
        $this->em->flush();
        self::assertSame([45, 60], [$side->getBlockSeconds(), $side->getLoopSeconds()]);
        self::assertSame(1000.0, $side->getDailyContacts(1)); // a slot is still 1/12 of the time on screen

        $crawler = $this->client->request('GET', $this->url($this->screen));
        self::assertSame(['A 9 × 5 сек из 12'], $crawler->filter('table tbody')->eq(0)->filter('th')->each(static fn ($th) => $th->text()));
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $values['booking_form']['startDate'] = '2026-09-12';
        $values['booking_form']['endDate'] = '2026-09-25';
        $values['booking_form']['slots'] = '10';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Слотов — от 1 до 9');

        $values['booking_form']['slots'] = '9';
        $this->submit($uri, $values);
        $crawler = $this->client->followRedirect();
        self::assertSame('9/9', trim($crawler->filter('table tbody')->eq(0)->filter('td')->eq(2)->text()));

        // the side form: on sale fewer than in the block, not the other way round
        $crawler = $this->client->request('GET', '/admin/products/'.$this->screen->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('12', $values['product_form']['sides'][0]['loopSlotCount']);
        $values['product_form']['sides'][0]['loopSlotCount'] = '8';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Всего слотов в блоке не может быть меньше, чем в продаже');
    }

    public function testSlotsOfABookingAreChanged(): void
    {
        $side = $this->side($this->screen, 'A');
        $hold = function (int $slots, string $client) use ($side): Booking {
            $request = new BookingRequest();
            $request->side = $side;
            $request->startDate = new \DateTimeImmutable('2026-09-12');
            $request->endDate = new \DateTimeImmutable('2026-09-25');
            $request->slots = $slots;
            $request->client = $this->customer;
            $request->clientName = $client;

            return static::getContainer()->get(BookingManager::class)->hold($request);
        };
        $mine = $hold(2, 'Кафе');
        $hold(8, 'Аптека');
        $this->hold($this->side($this->billboard, 'A'), '2026-10');

        $crawler = $this->client->request('GET', $this->url($this->screen));
        $form = $crawler->filter('form[action="/admin/bookings/'.$mine->getId().'/slots"]');
        self::assertSame('2', $form->filter('input[name="slots"]')->attr('value'));
        $change = function (string $slots) use ($form): void {
            $values = $form->form()->getPhpValues();
            $values['slots'] = $slots;
            $this->client->request('POST', $form->form()->getUri(), $values);
        };

        // 8 + 4 fill the block
        $change('4');
        self::assertResponseRedirects($this->url($this->screen));
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=alert]', 'Слотов в брони: 4');
        self::assertSame(4, $this->reload($mine)->getSlots());

        // one more doesn't fit, fewer always do
        $change('5');
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=alert]', 'свободно слотов: 4 из 12, а нужно 5');
        $change('13');
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role=alert]', 'Слотов — от 1 до 12');
        $change('1');
        self::assertSame(1, $this->reload($mine)->getSlots());

        // a whole side has no slots to change
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertCount(1, $crawler->filter('form[action$="/cancel"]'));
        self::assertCount(0, $crawler->filter('form[action$="/slots"]'));
    }

    public function testScreenTurnedIntoAStaticPosterTakesWholeSides(): void
    {
        $static = $this->em->getRepository(ProductType::class)->findOneBy(['name' => 'Статика']);
        $video = $this->em->getRepository(ProductType::class)->findOneBy(['name' => 'Видеоэкран']);
        // the side has the screen's type of its own (e.g. moved by the import) and a client's slot in September
        $this->side($this->screen, 'A')->setProductType($video);
        $this->em->flush();
        $request = new BookingRequest();
        $request->side = $this->side($this->screen, 'A');
        $request->startDate = new \DateTimeImmutable('2026-09-12');
        $request->endDate = new \DateTimeImmutable('2026-09-25');
        $request->slots = 1;
        $request->client = $this->customer;
        static::getContainer()->get(BookingManager::class)->hold($request);

        // the structure becomes a static poster with two sides
        $crawler = $this->client->request('GET', '/admin/products/'.$this->screen->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame((string) $video->getId(), $values['product_form']['sides'][0]['productType']);
        $values['product_form']['productType'] = (string) $static->getId();
        $values['product_form']['sides'][1] = ['name' => 'B', 'productType' => ''] + $values['product_form']['sides'][0];
        $this->submit($uri, $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $product = $this->em->find(Product::class, $this->screen->getId());
        self::assertSame(['A' => null, 'B' => null], array_combine(
            $product->getSides()->map(static fn (ProductSide $s) => $s->getName())->getValues(),
            $product->getSides()->map(static fn (ProductSide $s) => $s->getProductType()?->getName())->getValues(),
        ));
        self::assertFalse($product->hasAirtimeSides());

        // the old slot booking takes side A for its days, it isn't "1 slot of 12" any more
        $crawler = $this->client->request('GET', $this->url($product));
        self::assertSelectorNotExists('input[name="booking_form[slots]"]');
        self::assertSelectorTextContains('h2', 'Занятость на 12 месяцев');
        self::assertStringNotContainsString('слот', $crawler->filter('main')->text());
        $sideA = $product->getSides()->findFirst(static fn ($i, ProductSide $s) => 'A' === $s->getName());
        self::assertNotNull(static::getContainer()->get(BookingManager::class)->availabilityProblem($sideA, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'), null));
    }

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
