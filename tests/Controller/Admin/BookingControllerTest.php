<?php

namespace App\Tests\Controller\Admin;

use App\Dto\BookingRequest;
use App\Entity\AdditionalService;
use App\Entity\Booking;
use App\Entity\BookingServiceLine;
use App\Entity\Payment;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\BookingKind;
use App\Enum\BookingMode;
use App\Enum\ClientType;
use App\Enum\BookingStatus;
use App\Service\BookingException;
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

        // saved, it opens the new booking's card
        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'ждёт подтверждения до 11.09.2026 12:00');
        self::assertSelectorTextContains('main', 'ООО Ромашка');
        // the client card is linked, not just named
        self::assertSelectorExists('main a[href="/admin/clients/'.$this->customer->getId().'"]');

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
        // straight to the booking's row, on the tab of its first month
        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertSame('/admin/products/'.$this->billboard->getId().'/bookings?month=2026-09#booking-'.$booking->getId(), $bookings->filter('tbody a')->attr('href'));
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
        $this->client->followRedirect();
        $crawler = $this->client->request('GET', $this->url($this->screen));

        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertSame(14, $booking->getDays());
        self::assertSame(2, $booking->getSlots());
        self::assertSelectorTextContains('main', '12–25 сентября 2026');
        self::assertSelectorTextContains('main', '14 дн. · 2 слота');
        // the grid counts the slots on each booked day only
        $cells = $crawler->filter('tbody')->first()->filter('tr td');
        self::assertSame('0/12', trim($cells->eq(0)->text())); // 10th
        self::assertSame('2/12', trim($cells->eq(2)->text())); // 12th
        // a day with slots left books the screen from that day
        $day = $cells->eq(2)->filter('button[data-book-from]');
        self::assertSame('2026-09-12', $day->attr('data-book-from'));
        self::assertStringStartsWith('Занято слотов: 2 из 12', $day->attr('title'));
        self::assertSame('0/12', trim($cells->eq(16)->text())); // 26th
    }

    /** Managers enter placements afterwards, with past days and months; agents book from today on */
    public function testManagersBookBackDatedAgentsDoNot(): void
    {
        $book = function (Product $product, array $period): void {
            $crawler = $this->client->request('GET', $this->url($product));
            [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
            $values['booking_form'] = array_merge($values['booking_form'], $period, [
                'side' => (string) $this->side($product, 'A')->getId(),
                'client' => (string) $this->customer->getId(),
            ]);
            $this->submit($uri, $values);
        };

        // the screen: the first day in the past, no earliest day in the form
        self::assertNull($this->client->request('GET', $this->url($this->screen))->filter('#booking_form_startDate')->attr('min'));
        $book($this->screen, ['startDate' => '2026-08-20', 'endDate' => '2026-09-05', 'slots' => '1']);
        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());
        self::assertSame('2026-08-20', $booking->getStartDate()->format('Y-m-d'));

        // the billboard: a month gone, from the year back on offer
        $options = $this->client->request('GET', $this->url($this->billboard))->filter('#booking_form_startMonth option')->each(static fn ($o) => $o->attr('value'));
        self::assertSame('2025-09', $options[0]);
        $book($this->billboard, ['startMonth' => '2026-07', 'months' => '1']);
        self::assertSame(2, $this->em->getRepository(Booking::class)->count([]));
        self::assertNotNull($this->em->getRepository(Booking::class)->findOneBy(['startDate' => new \DateTimeImmutable('2026-07-01')]));

        // an agent: from today on, as before
        $this->client->loginUser($this->createUser('agent@redbox.local', User::ROLE_AGENT));
        self::assertSame('2026-09-10', $this->client->request('GET', $this->url($this->screen))->filter('#booking_form_startDate')->attr('min'));
        $book($this->screen, ['startDate' => '2026-09-01', 'endDate' => '2026-09-20', 'slots' => '1']);
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
        self::assertSame('A3/12', trim($screenRow->filter('[title^="Сторона A"]')->text())); // side name, then the slots (spaced by CSS)
        self::assertStringContainsString('занято слотов: 3 из 12', $screenRow->filter('[title^="Сторона A"]')->attr('title'));
        $billboardRow = $crawler->filter('tbody tr')->reduce(static fn ($row) => str_contains($row->text(), 'Щит на Ленина'));
        self::assertStringNotContainsString('/', $billboardRow->filter('[title^="Сторона A"]')->text());

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

    /**
     * Shipments at the top of the page: a confirmed booking brings its "Продано за" to a month by the days it runs
     * there, the months of a booking adding up to its price exactly; holds and cancelled bookings ship nothing.
     */
    public function testStructurePageShowsShipmentsByDays(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $long = $this->hold($this->side($this->billboard, 'A'), '2026-09', 'Надолго', months: 3); // 30 + 31 + 30 days
        $manager->setSoldPrice($long, 90000);
        $manager->markPaid($long);
        $manager->setSoldPrice($this->hold($this->side($this->billboard, 'B'), '2026-09', 'На месяц'), 20000);
        $cancelled = $this->hold($this->side($this->billboard, 'B'), '2026-10', 'Передумал');
        $manager->setSoldPrice($cancelled, 50000);
        $manager->cancel($cancelled);
        $manager->confirm($this->hold($this->side($this->billboard, 'B'), '2026-11', 'Без суммы'));

        $page = fn (string $query) => $this->client->request('GET', $this->url($this->billboard).$query);
        $revenue = fn (string $query) => preg_replace('/\s+/u', ' ', trim($page($query)->filter('[data-revenue]')->text()));

        // the card is above the occupancy grids and the bookings list
        $html = $page('')->html();
        self::assertLessThan(strpos($html, 'id="booking-grids"'), strpos($html, 'id="booking-revenue"'));

        // 90 000 × 30 / 91; the hold of 20 000 is not shipped yet
        self::assertStringContainsString('Отгрузка за сентябрь 2026 Итого 29 670,33 ₽', $revenue(''));
        self::assertStringContainsString('Оплачено 29 670,33 ₽ Ждёт оплаты 0 ₽', $revenue(''));
        self::assertStringContainsString('Ждут подтверждения: 1 на 20 000 ₽', $revenue(''));
        // 90 000 × 61 / 91 − September; a cancelled booking brings nothing
        self::assertStringContainsString('Итого 30 659,34 ₽', $revenue('?month=2026-10'));
        // the rest of the 90 000, and a confirmed booking without a price
        self::assertStringContainsString('Итого 29 670,33 ₽', $revenue('?month=2026-11'));
        self::assertStringContainsString('без суммы: 1', $revenue('?month=2026-11'));
        self::assertStringContainsString('Отгрузка за всё время Итого 90 000 ₽', $revenue('?month=all'));

        // every month of the structure, a month leading to its tab
        $months = $page('')->filter('[data-shipments] a')->each(static fn ($a) => [$a->attr('data-shipment'), preg_replace('/\s+/u', ' ', $a->filter('span')->last()->text())]);
        self::assertSame([['2026-09', '29 670,33 ₽'], ['2026-10', '30 659,34 ₽'], ['2026-11', '29 670,33 ₽']], $months);
    }

    /** The bookings of a structure: one side at a time, sorted by a column; an action keeps both */
    public function testStructureBookingsBySideAndSortedByColumns(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $a = $this->hold($this->side($this->billboard, 'A'), '2026-09', 'Сторона А');
        $manager->setSoldPrice($a, 10000);
        $b = $this->hold($this->side($this->billboard, 'B'), '2026-09', 'Сторона Б');
        $manager->setSoldPrice($b, 30000);
        $sideB = $this->side($this->billboard, 'B')->getId();

        $rows = fn (string $query) => $this->client->request('GET', $this->url($this->billboard).$query)->filter('#booking-list tbody tr')
            ->each(static fn ($row) => $row->attr('id'));
        $idA = 'booking-'.$a->getId();
        $idB = 'booking-'.$b->getId();

        self::assertSame([$idB], $rows('?list_side='.$sideB));
        self::assertSelectorTextContains('[data-list-sides] .chip-on', 'B');
        // the tab's shipments are of that side alone: nothing confirmed on B yet
        self::assertSelectorTextContains('[data-revenue]', "Ждут подтверждения: 1 на 30\u{a0}000");

        self::assertSame([$idA, $idB], $rows('?sort=side'));
        self::assertSame([$idB, $idA], $rows('?sort=side&dir=desc'));
        // by the price the biggest first, a second click the other way
        self::assertSame([$idB, $idA], $rows('?sort=sold'));
        self::assertSame('sold', $this->client->getCrawler()->filter('th[aria-sort="descending"] a')->attr('data-list-sort'));
        self::assertSame([$idA, $idB], $rows('?sort=sold&dir=asc'));

        // confirming from the list comes back to the same side and order
        $page = $this->url($this->billboard).'?list_side='.$sideB.'&sort=sold';
        $crawler = $this->client->request('GET', $page);
        $form = $crawler->filter('form[action="/admin/bookings/'.$b->getId().'/confirm"]')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_REFERER' => 'http://localhost'.$page]);
        self::assertResponseRedirects($this->url($this->billboard).'?list_side='.$sideB.'&sort=sold');
    }

    /**
     * The booking's payment schedule: its price split by the months it runs, due on the day picked, in the payment
     * calendar; the first payment confirms the hold, the last one marks the booking paid.
     */
    public function testBookingIsSplitIntoPaymentsByMonth(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-09', months: 3); // 30 + 31 + 30 days
        $manager->setSoldPrice($booking, 90000);
        $booking->addServiceLine(new BookingServiceLine('Монтаж', 'выезд', '1', '3000'));
        $this->em->flush();
        $card = '/admin/bookings/'.$booking->getId();

        $crawler = $this->client->request('GET', $card);
        $form = $crawler->filter('form[data-booking-schedule]')->form();
        $form['day'] = '31';
        $this->client->submit($form);
        self::assertResponseRedirects($card.'#payments');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'График платежей составлен: 3');
        self::assertSelectorNotExists('form[data-booking-schedule]'); // once

        // each month pays for its days, the services go into the first one; a short month — its last day
        $payments = $this->em->getRepository(Payment::class)->findBy([], ['dueDate' => 'ASC']);
        self::assertSame(['32670.33', '30659.34', '29670.33'], array_map(static fn (Payment $p) => $p->getAmount(), $payments));
        self::assertSame(['2026-09-30', '2026-10-31', '2026-11-30'], array_map(static fn (Payment $p) => $p->getDueDate()->format('Y-m-d'), $payments));
        self::assertSame('Бронь «Щит на Ленина», сторона A — Сентябрь 2026 и услуги', $payments[0]->getTitle());
        self::assertSame($this->customer->getId(), $payments[0]->getClient()->getId());
        self::assertCount(3, $crawler->filter('#payments tbody tr[data-payment]'));

        // in the payment calendar, with a link back to the booking
        self::assertStringContainsString('Бронь #'.$booking->getId(), $this->client->request('GET', '/admin/payments?month=2026-10')->filter('#list')->text());

        $pay = function (Payment $payment): void {
            $crawler = $this->client->request('GET', '/admin/bookings/'.$payment->getBooking()->getId());
            $this->submitPostForm($crawler, '#payments form[action="/admin/payments/'.$payment->getId().'/pay"]');
            self::assertResponseRedirects('/admin/bookings/'.$payment->getBooking()->getId().'#payments');
        };

        // the first payment fixes the side, the booking is not paid yet
        $pay($payments[0]);
        $booking = $this->reload($booking);
        self::assertSame(BookingStatus::Confirmed, $booking->getStatus());
        self::assertFalse($booking->isPaid());

        // all of them: paid
        $payments = $booking->getPayments()->getValues();
        $pay($payments[1]);
        $pay($payments[2]);
        self::assertTrue($this->reload($booking)->isPaid());

        // one taken back: not paid in full any more, still confirmed
        $payment = $this->reload($booking)->getPayments()->last();
        $crawler = $this->client->request('GET', $card);
        $this->submitPostForm($crawler, '#payments form[action="/admin/payments/'.$payment->getId().'/unpay"]');
        $booking = $this->reload($booking);
        self::assertFalse($booking->isPaid());
        self::assertSame(BookingStatus::Confirmed, $booking->getStatus());

        // "Оплачено" on the booking pays what is left of its schedule
        $crawler = $this->client->request('GET', $card);
        $this->submitPostForm($crawler, 'form[action="'.$card.'/pay"]');
        self::assertTrue($this->reload($booking)->getPayments()->forAll(static fn (int $i, Payment $p) => $p->isPaid()));
    }

    /**
     * Social advertising and a bonus are free, a certificate carries its own sum: they take the side all the same,
     * but bring no money — the certificate's sum is counted apart — and wait for no payment.
     */
    public function testBookingKindsSocialBonusAndCertificate(): void
    {
        $book = function (string $side, string $kind, string $price, string $certificate = '') {
            $crawler = $this->client->request('GET', $this->url($this->billboard));
            [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
            $values['booking_form']['side'] = (string) $this->side($this->billboard, $side)->getId();
            $values['booking_form']['startMonth'] = '2026-09';
            $values['booking_form']['months'] = '1';
            $values['booking_form']['client'] = (string) $this->customer->getId();
            $values['booking_form']['kind'] = $kind;
            $values['booking_form']['soldPrice'] = $price;
            $values['booking_form']['certificateAmount'] = $certificate;
            $this->submit($uri, $values);
        };

        // a certificate without its sum is refused
        $book('A', 'certificate', '');
        self::assertSelectorTextContains('form[name="booking_form"]', 'Укажите сумму сертификата');
        self::assertSame(0, $this->em->getRepository(Booking::class)->count([]));

        // the price typed in is dropped: neither is sold for money
        $book('A', 'certificate', '60000', '50000');
        $book('B', 'social', '30000');
        $certificate = $this->em->getRepository(Booking::class)->findOneBy(['kind' => BookingKind::Certificate]);
        $social = $this->em->getRepository(Booking::class)->findOneBy(['kind' => BookingKind::Social]);
        self::assertSame(50000.0, $certificate->getCertificateAmount());
        self::assertNull($certificate->getSoldPrice());
        self::assertNull($social->getSoldPrice());
        self::assertNull($social->getCertificateAmount());

        foreach ([$certificate, $social] as $booking) {
            $this->submitPostForm($this->client->request('GET', $this->url($this->billboard)), 'form[action="/admin/bookings/'.$booking->getId().'/confirm"]');
        }
        try {
            static::getContainer()->get(BookingManager::class)->setSoldPrice($this->reload($social), 1000);
            self::fail('Expected BookingException');
        } catch (BookingException $e) {
            self::assertStringContainsString('«Соцреклама»', $e->getMessage());
        }

        // the list shows the kind instead of the price, and no "Оплачено"
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertStringContainsString("на 50\u{a0}000", $crawler->filter('#booking-'.$certificate->getId().' [data-booking-kind="certificate"]')->text());
        self::assertStringContainsString('бесплатно', $crawler->filter('#booking-'.$social->getId().' [data-booking-kind="social"]')->text());
        self::assertCount(0, $crawler->filter('form[action="/admin/bookings/'.$social->getId().'/price"]'));
        self::assertCount(0, $crawler->filter('form[action="/admin/bookings/'.$social->getId().'/pay"]'));

        // the side is taken, no money is shipped, the certificate's sum apart
        $revenue = preg_replace('/\s+/u', ' ', $crawler->filter('[data-revenue]')->text());
        self::assertStringContainsString('Итого 0 ₽', $revenue);
        self::assertStringContainsString('По сертификатам: 50 000 ₽', $revenue);
        self::assertStringContainsString('Бесплатно (соцреклама, бонус): 1 бронь', $revenue);
        self::assertStringNotContainsString('без суммы', $revenue);

        // they wait for no payment
        $crawler = $this->client->request('GET', '/admin/bookings');
        self::assertSame('0', trim($crawler->filter('a[data-live-link]:contains("не оплачены") .chip-count')->text()));

        // changed into a commercial booking: the certificate's sum goes, a price may be set again
        $crawler = $this->client->request('GET', '/admin/bookings/'.$certificate->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        $values['booking_form']['kind'] = 'commercial';
        $values['booking_form']['soldPrice'] = '45000';
        $this->submit($uri, $values);
        $certificate = $this->reload($certificate);
        self::assertSame(BookingKind::Commercial, $certificate->getKind());
        self::assertNull($certificate->getCertificateAmount());
        self::assertSame(45000.0, $certificate->getSoldPrice());
    }

    /** Services sold with a booking: picked when booking, added and removed later in the list, counted apart in the revenue */
    public function testSoldPriceIsTypedWithKopecksInTheList(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-09');
        $form = $this->client->request('GET', $this->url($this->billboard))->filter('form[action="/admin/bookings/'.$booking->getId().'/price"]');
        $change = function (string $price) use ($form): void {
            $values = $form->form()->getPhpValues();
            $values['price'] = $price;
            $this->client->request('POST', $form->form()->getUri(), $values);
            $this->client->followRedirect();
        };

        $change('45 000,50');
        self::assertAnySelectorTextContains('[role=alert]', "Продано за 45\u{00A0}000,50\u{00A0}₽");
        self::assertSame(45000.5, $this->reload($booking)->getSoldPrice());
        self::assertSame('45 000,50', $this->client->getCrawler()->filter('input[name="price"]')->attr('value'));

        $change('38000.4');
        self::assertSame(38000.4, $this->reload($booking)->getSoldPrice());

        // a typo is refused, the price stays
        $change('38,000,40');
        self::assertAnySelectorTextContains('[role=alert]', 'Введите сумму числом');
        self::assertSame(38000.4, $this->reload($booking)->getSoldPrice());

        $change('');
        self::assertNull($this->reload($booking)->getSoldPrice());
    }

    public function testBookingCarriesServices(): void
    {
        $printing = (new AdditionalService())->setName('Печать баннера')->setUnit('м²')->setPrice('350.00')->setCostPrice('200.00');
        $mounting = (new AdditionalService())->setName('Монтаж')->setUnit('выезд')->setPrice('3000.00');
        $this->em->persist($printing);
        $this->em->persist($mounting);
        $this->em->flush();

        // picked in the new booking form: a catalog entry fills what was left empty, a custom line is typed in
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        [$values, $uri] = $this->formValues($crawler, 'Забронировать на 24 часа');
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'A')->getId();
        $values['booking_form']['startMonth'] = '2026-09';
        $values['booking_form']['months'] = '2';
        $values['booking_form']['client'] = (string) $this->customer->getId();
        $values['booking_form']['soldPrice'] = '60000';
        $values['booking_form']['services'] = [
            ['service' => (string) $printing->getId(), 'name' => '', 'quantity' => '18', 'unit' => '', 'unitPrice' => ''],
            ['service' => '', 'name' => 'Дизайн макета', 'quantity' => '1', 'unit' => 'макет', 'unitPrice' => '2500'],
        ];
        $this->submit($uri, $values);
        $booking = $this->em->getRepository(Booking::class)->findOneBy([]);
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());

        $lines = $booking->getServiceLines()->getValues();
        self::assertSame(['Печать баннера', 'Дизайн макета'], array_map(fn ($l) => $l->getName(), $lines));
        self::assertSame('м²', $lines[0]->getUnit());
        self::assertSame(6300.0, $lines[0]->getTotal());
        self::assertSame(200.0, $lines[0]->getUnitCost());
        self::assertSame(8800.0, $booking->getServicesTotal());

        // added in the list: the catalog price unless typed otherwise
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        $form = $crawler->filter('form[action="/admin/bookings/'.$booking->getId().'/services"]')->form();
        $values = $form->getPhpValues();
        $values['booking_service_'.$booking->getId()]['service'] = (string) $mounting->getId();
        $this->submit($form->getUri(), $values);
        self::assertResponseRedirects($this->url($this->billboard));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Услуга «Монтаж» добавлена к брони');
        self::assertSame(11800.0, $this->reload($booking)->getServicesTotal());

        // not confirmed yet: nothing shipped, the hold awaits confirmation (compared without spaces)
        $total = fn (string $query) => preg_replace('/\s+/u', '', $this->client->request('GET', $this->url($this->billboard).$query)->filter('[data-revenue]')->text());
        self::assertStringContainsString('Итого0₽', $total(''));
        self::assertStringContainsString('Ждутподтверждения:1на41308,20₽', $total(''));

        // paid: the placement by days (60 000 × 30 / 61), the services whole in the month the booking starts
        $this->submitPostForm($this->client->request('GET', $this->url($this->billboard)), 'form[action="/admin/bookings/'.$booking->getId().'/pay"]');
        self::assertStringContainsString('Итого41308,20₽Размещение29508,20₽Услуги11800₽Оплачено41308,20₽Ждётоплаты0₽', $total(''));
        self::assertStringContainsString('Итого30491,80₽Размещение30491,80₽Услуги0₽', $total('?month=2026-10'));
        self::assertStringContainsString('Итого71800₽Размещение60000₽Услуги11800₽', $total('?month=all'));

        // removed in the list
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        $line = $this->reload($booking)->getServiceLines()->first();
        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$booking->getId().'/services/'.$line->getId().'/delete"]');
        self::assertResponseRedirects($this->url($this->billboard));
        self::assertSame(['Дизайн макета', 'Монтаж'], array_map(fn ($l) => $l->getName(), $this->reload($booking)->getServiceLines()->getValues()));

        // a cancelled booking takes no more services
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        $this->submitPostForm($crawler, 'form[action="/admin/bookings/'.$booking->getId().'/cancel"]');
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertCount(0, $crawler->filter('form[action$="/services"]'));
        self::assertStringContainsString('Монтаж', $crawler->filter('.table-card')->last()->text());
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
            self::assertStringStartsWith('/admin/bookings/', $this->client->getResponse()->headers->get('Location'));
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
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', $this->url($this->screen));
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

    public function testListFiltersByMonthKindAndAuthorAndCountsStatuses(): void
    {
        $this->hold($this->side($this->billboard, 'A'), '2026-10', 'Октябрь');
        $this->hold($this->side($this->billboard, 'B'), '2026-12', 'Декабрь', months: 2);
        $request = new BookingRequest();
        $request->side = $this->side($this->screen, 'A');
        $request->startDate = new \DateTimeImmutable('2026-10-20');
        $request->endDate = new \DateTimeImmutable('2026-11-05');
        $request->client = $this->customer;
        $request->clientName = 'Экранный';
        $request->clientPhone = '+7 900 000-00-00';
        $me = $this->em->getRepository(User::class)->findOneBy(['email' => 'me@redbox.local']);
        $manager = static::getContainer()->get(BookingManager::class);
        $manager->confirm($manager->hold($request, $me));

        $names = fn (string $query): array => $this->client->request('GET', '/admin/bookings'.$query)->filter('tbody tr')
            ->each(static fn ($row) => trim($row->filter('td')->eq(2)->filter('.text-xs')->last()->text()));
        $clients = fn (string $query): array => array_map(static fn (string $text) => explode(' · ', $text)[0], $names($query));

        // a booking is in a month it takes at least a day of
        self::assertSame(['Экранный', 'Октябрь'], $clients('?month=2026-10'));
        self::assertSame(['Экранный'], $clients('?month=2026-11'));
        self::assertSame(['Декабрь'], $clients('?month=2027-01'));
        self::assertSame(['Экранный'], $clients('?kind=airtime'));
        self::assertSame(['Декабрь', 'Октябрь'], $clients('?kind=side'));
        self::assertSame(['Экранный'], $clients('?author='.$me->getId()));
        // side "А" takes the Latin "A" too, "В" the Latin "B"
        self::assertSame(['Экранный', 'Октябрь'], $clients('?side=А'));
        self::assertSame(['Декабрь'], $clients('?side=В'));

        // the chips count under the other filters
        $crawler = $this->client->request('GET', '/admin/bookings?month=2026-10');
        self::assertSame('Все 2', trim($crawler->filter('a[data-live-link]:contains("Все")')->text()));
        self::assertSame('Ждёт подтверждения 1', trim($crawler->filter('a[data-live-link]:contains("Ждёт")')->text()));
        self::assertSame('Подтверждена 1', trim($crawler->filter('a[data-live-link]:contains("Подтверждена")')->text()));
        self::assertSame('/admin/bookings?month=2026-10&status=hold', $crawler->filter('a[data-live-link]:contains("Ждёт")')->attr('href'));
        self::assertSame($me->getId().'', $crawler->filter('select[name="author"] option')->eq(1)->attr('value'));
        // the actions in the list are sent without a reload
        self::assertSelectorExists('[data-ajax-forms] #booking-results');

        $this->client->request('GET', '/admin/bookings?kind=nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testGridCellsBookOrLeadToTheirBooking(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-10');

        $crawler = $this->client->request('GET', $this->url($this->billboard));
        $rowA = $crawler->filter('tbody')->first()->filter('tr')->eq(0);
        // September is free: a click fills the form with side A from that month
        $free = $rowA->filter('button[data-book-from="2026-09"]');
        self::assertSame((string) $this->side($this->billboard, 'A')->getId(), $free->attr('data-book-side'));
        // October is taken: a link to the booking's row on the October tab
        self::assertSame($this->url($this->billboard).'?month=2026-10#booking-'.$booking->getId(), $rowA->filter('a[data-booking-link]')->attr('href'));
        $crawler = $this->client->request('GET', $this->url($this->billboard).'?month=2026-10');
        self::assertCount(1, $crawler->filter('#booking-'.$booking->getId()));
        self::assertSelectorExists('form[data-booking-form][data-min-days]');
        // the grids and the list are swapped in place after an action, the new booking form is not
        self::assertSelectorExists('#booking-grids[data-ajax-region]');
        self::assertSelectorExists('#booking-list[data-ajax-region][data-ajax-forms]');
        self::assertCount(0, $crawler->filter('[data-ajax-forms] form[data-booking-form]'));

        // a side out of order: nothing to click
        // found anew: a request in between resets the entity manager
        $this->em->find(ProductSide::class, $this->side($this->billboard, 'B')->getId())->setWorking(false);
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->url($this->billboard));
        self::assertCount(0, $crawler->filter('tbody')->first()->filter('tr')->eq(1)->filter('button[data-book-from]'));
    }

    public function testStructuresListLeadsToTheBookingOfASide(): void
    {
        $sideB = $this->side($this->billboard, 'B');

        $crawler = $this->client->request('GET', '/admin/products?month=2026-11');
        $chip = $crawler->filter('tbody tr:contains("Щит на Ленина") a:contains("B")');
        self::assertSame($this->url($this->billboard).'?side='.$sideB->getId().'&from=2026-11#new-booking', $chip->attr('href'));

        // the form starts with that side and month
        $crawler = $this->client->request('GET', $this->url($this->billboard).'?side='.$sideB->getId().'&from=2026-11');
        self::assertSame((string) $sideB->getId(), $crawler->filter('select[name="booking_form[side]"] option[selected]')->attr('value'));
        self::assertSame('2026-11', $crawler->filter('select[name="booking_form[startMonth]"] option[selected]')->attr('value'));
        // a screen starts on the 1st of a month ahead
        $crawler = $this->client->request('GET', $this->url($this->screen).'?from=2026-11');
        self::assertSame('2026-11-01', $crawler->filter('input[name="booking_form[startDate]"]')->attr('value'));
        self::assertSame('2026-11-14', $crawler->filter('input[name="booking_form[endDate]"]')->attr('value'));
    }

    public function testStructuresListSortsByColumns(): void
    {
        $manager = static::getContainer()->get(BookingManager::class);
        $manager->markPaid($this->hold($this->side($this->billboard, 'A'), '2026-09'));
        $manager->markPaid($this->hold($this->side($this->billboard, 'B'), '2026-09'));
        $this->screen->setPrice('50000');
        $this->em->flush();

        $names = fn (string $query): array => $this->client->request('GET', '/admin/products'.$query)->filter('tbody tr')
            ->each(static fn ($row) => trim($row->filter('th .font-medium')->first()->text()));

        self::assertSame(['Щит на Ленина', 'Экран на площади'], $names('?sort=name'));
        self::assertSame(['Экран на площади', 'Щит на Ленина'], $names('?sort=name&dir=desc'));
        self::assertSame(['Экран на площади', 'Щит на Ленина'], $names('?sort=status')); // free first
        self::assertSame(['Щит на Ленина', 'Экран на площади'], $names('?sort=status&dir=desc'));
        self::assertSame(['Щит на Ленина', 'Экран на площади'], $names('?sort=price'));

        // the header: the active column turns around, then back to the recently updated; filters stay
        $crawler = $this->client->request('GET', '/admin/products?status=occupied&sort=price');
        self::assertSame('/admin/products?status=occupied&sort=price&dir=desc', $crawler->filter('thead a:contains("Цена")')->attr('href'));
        self::assertSame('/admin/products?status=occupied&sort=name', $crawler->filter('thead a:contains("Конструкция")')->attr('href'));
        $crawler = $this->client->request('GET', '/admin/products?sort=price&dir=desc');
        self::assertSame('/admin/products', $crawler->filter('thead a:contains("Цена")')->attr('href'));
    }

    public function testBookingCardShowsEverythingAndActsInPlace(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-10', 'Иван', months: 2);

        $crawler = $this->client->request('GET', '/admin/bookings/'.$booking->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Щит на Ленина, сторона A');
        self::assertSelectorTextContains('main', 'Октябрь 2026 — Ноябрь 2026');
        self::assertSelectorTextContains('main', 'ООО Ромашка');
        self::assertSelectorExists('a[href="/admin/bookings/'.$booking->getId().'/edit"]');
        // the lists lead here
        $list = $this->client->request('GET', '/admin/bookings');
        self::assertCount(1, $list->filter('tbody a[href="/admin/bookings/'.$booking->getId().'"]'));

        // the buttons come back to the card
        $this->submitPostForm($crawler, 'form[action$="/confirm"]');
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Подтверждена');
        $form = $crawler->filter('form[action="/admin/bookings/'.$booking->getId().'/services"]')->form();
        $values = $form->getPhpValues();
        $values['booking_service_'.$booking->getId()]['name'] = 'Монтаж';
        $values['booking_service_'.$booking->getId()]['quantity'] = '1';
        $values['booking_service_'.$booking->getId()]['unit'] = 'шт';
        $values['booking_service_'.$booking->getId()]['unitPrice'] = '3000';
        $this->submit($form->getUri(), $values);
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());
        self::assertSame(3000.0, $this->reload($booking)->getServicesTotal());
    }

    public function testBookingIsChangedOnItsCard(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-10', 'Иван');
        static::getContainer()->get(BookingManager::class)->confirm($booking);
        $this->hold($this->side($this->billboard, 'B'), '2026-12', 'Сосед');

        $crawler = $this->client->request('GET', '/admin/bookings/'.$booking->getId().'/edit');
        self::assertResponseIsSuccessful();
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('2026-10', $values['booking_form']['startMonth']);
        self::assertArrayNotHasKey('services', $values['booking_form']);

        // side B is taken in December
        $values['booking_form']['side'] = (string) $this->side($this->billboard, 'B')->getId();
        $values['booking_form']['startMonth'] = '2026-11';
        $values['booking_form']['months'] = '2';
        $this->submit($uri, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'Сторона B уже забронирована');
        self::assertSame('A', $this->reload($booking)->getSide()->getName());

        // November only: free; the contact, the comment and the price change too
        $values['booking_form']['months'] = '1';
        $values['booking_form']['clientName'] = 'Пётр';
        $values['booking_form']['clientPhone'] = '+7 900 555-44-33';
        $values['booking_form']['comment'] = 'Перенесли на ноябрь';
        $values['booking_form']['soldPrice'] = '42000,50';
        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Бронь изменена');

        $booking = $this->reload($booking);
        self::assertSame(['B', '2026-11-01', '2026-11-30', 'Пётр', '+7 900 555-44-33', 'Перенесли на ноябрь', 42000.5, BookingStatus::Confirmed],
            [$booking->getSide()->getName(), $booking->getStartDate()->format('Y-m-d'), $booking->getEndDate()->format('Y-m-d'), $booking->getClientName(), $booking->getClientPhone(), $booking->getComment(), $booking->getSoldPrice(), $booking->getStatus()]);
    }

    public function testScreenBookingChangesItsDaysAndSlots(): void
    {
        $request = new BookingRequest();
        $request->side = $this->side($this->screen, 'A');
        $request->startDate = new \DateTimeImmutable('2026-09-12');
        $request->endDate = new \DateTimeImmutable('2026-09-25');
        $request->slots = 1;
        $request->client = $this->customer;
        $booking = static::getContainer()->get(BookingManager::class)->hold($request);

        $crawler = $this->client->request('GET', '/admin/bookings/'.$booking->getId().'/edit');
        [$values, $uri] = $this->formValues($crawler, 'Сохранить');
        self::assertSame('2026-09-12', $values['booking_form']['startDate']);
        $values['booking_form']['endDate'] = '2026-09-30';
        $values['booking_form']['slots'] = '3';
        $this->submit($uri, $values);
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());

        $booking = $this->reload($booking);
        self::assertSame([19, 3, BookingStatus::Hold], [$booking->getDays(), $booking->getSlots(), $booking->getStatus()]);
    }

    public function testBookingNoLongerInForceIsNotChanged(): void
    {
        $booking = $this->hold($this->side($this->billboard, 'A'), '2026-10');
        static::getContainer()->get(BookingManager::class)->cancel($booking);

        $crawler = $this->client->request('GET', '/admin/bookings/'.$booking->getId());
        self::assertSelectorNotExists('a[href="/admin/bookings/'.$booking->getId().'/edit"]');
        $this->client->request('GET', '/admin/bookings/'.$booking->getId().'/edit');
        self::assertResponseRedirects('/admin/bookings/'.$booking->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Эта бронь уже не действует');
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

    private function hold(ProductSide $side, string $month, string $client = 'ООО Ромашка', int $months = 1): Booking
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startMonth = $month;
        $request->months = $months;
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
