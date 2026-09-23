<?php

namespace App\Tests\Service;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Category;
use App\Entity\District;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\ProductSidePhoto;
use App\Entity\ProductType;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Service\BookingException;
use App\Service\BookingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class BookingManagerTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private BookingManager $manager;
    private EntityManagerInterface $em;
    private MockClock $clock;
    private ProductSide $billboardA;
    private ProductSide $billboardB;
    private ProductSide $screen;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-09-10 12:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->manager = static::getContainer()->get(BookingManager::class);

        foreach ([Booking::class, ProductSidePhoto::class, ProductSide::class, Product::class, ProductType::class, Category::class, District::class, User::class] as $class) {
            $this->em->createQuery(\sprintf('DELETE FROM %s e', $class))->execute();
        }

        $category = (new Category())->setName('Билборд 6х3');
        $static = (new ProductType())->setName('Статика');
        $video = (new ProductType())->setName('Видеоэкран')->setBookingMode(BookingMode::Airtime);
        $billboard = (new Product())->setName('Щит')->setCategory($category)->setProductType($static)
            ->addSide($this->billboardA = (new ProductSide())->setName('A'))
            ->addSide($this->billboardB = (new ProductSide())->setName('B'));
        $screenProduct = (new Product())->setName('Экран')->setCategory($category)->setProductType($video)
            ->addSide($this->screen = (new ProductSide())->setName('A'));

        foreach ([$category, $static, $video, $billboard, $screenProduct] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testNewBookingIsA24HourHold(): void
    {
        $booking = $this->book($this->billboardA, '2026-09');

        self::assertSame(BookingStatus::Hold, $booking->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-09-11 12:00:00'), $booking->getExpiresAt());
        self::assertEquals(new \DateTimeImmutable('2026-09-01'), $booking->getStartDate());
        self::assertEquals(new \DateTimeImmutable('2026-09-30'), $booking->getEndDate());
        self::assertTrue($booking->isWholeMonths());
        self::assertNull($booking->getSlots());
    }

    public function testSideCanBeBookedOncePerMonth(): void
    {
        $this->book($this->billboardA, '2026-09');

        $this->assertUnavailable(fn () => $this->book($this->billboardA, '2026-09'), 'Сторона A уже забронирована: Сентябрь 2026 (ООО Ромашка)');
        $this->assertUnavailable(fn () => $this->book($this->billboardA, '2026-08', months: 2), 'Сентябрь 2026');

        // other months and the other side are free
        self::assertInstanceOf(Booking::class, $this->book($this->billboardA, '2026-10'));
        self::assertInstanceOf(Booking::class, $this->book($this->billboardB, '2026-09'));
    }

    public function testMultiMonthBookingBlocksEveryMonth(): void
    {
        $booking = $this->book($this->billboardA, '2026-09', months: 3);
        self::assertSame(3, $booking->getMonthCount());
        self::assertEquals(new \DateTimeImmutable('2026-11-30'), $booking->getEndDate());

        $this->assertUnavailable(fn () => $this->book($this->billboardA, '2026-11'), 'Ноябрь 2026');
        self::assertInstanceOf(Booking::class, $this->book($this->billboardA, '2026-12'));
    }

    public function testUnpaidHoldIsReleasedAfter24Hours(): void
    {
        $hold = $this->book($this->billboardA, '2026-09');

        $this->clock->modify('+23 hours');
        $this->assertUnavailable(fn () => $this->book($this->billboardA, '2026-09'), 'уже забронирована');

        $this->clock->modify('+1 hour'); // exactly 24h later
        self::assertSame(BookingStatus::Expired, $hold->getStatusAt($this->clock->now()));
        self::assertInstanceOf(Booking::class, $this->book($this->billboardA, '2026-09', client: 'Второй клиент'));
    }

    public function testPaidBookingNeverExpires(): void
    {
        $booking = $this->book($this->billboardA, '2026-09');
        $this->clock->modify('+2 hours');
        $this->manager->markPaid($booking);

        self::assertSame(BookingStatus::Paid, $booking->getStatus());
        self::assertNull($booking->getExpiresAt());

        $this->clock->modify('+30 days');
        self::assertSame(0, $this->manager->expireOverdueHolds());
        $this->assertUnavailable(fn () => $this->book($this->billboardA, '2026-09'), 'уже забронирована');
    }

    public function testCannotPayExpiredHold(): void
    {
        $booking = $this->book($this->billboardA, '2026-09');
        $this->clock->modify('+25 hours');

        $this->assertUnavailable(fn () => $this->manager->markPaid($booking), 'Срок брони истёк');
    }

    public function testCancelFreesTheSide(): void
    {
        $booking = $this->book($this->billboardA, '2026-09');
        $this->manager->cancel($booking);

        self::assertSame(BookingStatus::Cancelled, $booking->getStatus());
        self::assertInstanceOf(Booking::class, $this->book($this->billboardA, '2026-09'));
        $this->assertUnavailable(fn () => $this->manager->cancel($booking), 'уже не действует');
    }

    public function testScreenBlockHoldsUpToItsSlots(): void
    {
        self::assertSame(BookingMode::DEFAULT_SLOT_COUNT, $this->screen->getSlotCount()); // 12 × 5 s
        $bookings = [];
        for ($i = 1; $i <= 4; ++$i) { // 4 × 3 slots = 12
            $bookings[] = $this->book($this->screen, '2026-09', slots: 3, client: 'Клиент '.$i);
        }

        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', slots: 1), 'свободно слотов: 0 из 12');
        // the next month is a separate block
        self::assertSame(1, $this->book($this->screen, '2026-10', slots: 1)->getSlots());

        $this->manager->cancel($bookings[0]); // frees 3 slots
        $this->book($this->screen, '2026-09', slots: 2);
        $this->book($this->screen, '2026-09', slots: 1);
        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', slots: 1), 'свободно слотов: 0 из 12');
    }

    public function testScreenReportsFreeSlotsWhenTheyDoNotFit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->book($this->screen, '2026-09', slots: 2);
        }

        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', slots: 3), 'свободно слотов: 2 из 12, а нужно 3');
        self::assertSame(2, $this->book($this->screen, '2026-09', slots: 2)->getSlots());
    }

    public function testSlotsAreSetPerScreen(): void
    {
        $this->screen->setSlotSeconds(10)->setSlotCount(2);
        $this->em->flush();
        self::assertSame(20, $this->screen->getBlockSeconds());

        $this->book($this->screen, '2026-09', slots: 1);
        $this->book($this->screen, '2026-09', slots: 1, client: 'Второй');
        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', slots: 1, client: 'Третий'), 'свободно слотов: 0 из 2');
        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-10', slots: 3), 'укажите число слотов: от 1 до 2');
    }

    public function testExpireOverdueHoldsOnlyTouchesUnpaidHolds(): void
    {
        $unpaid = $this->book($this->billboardA, '2026-09');
        $paid = $this->book($this->billboardB, '2026-09');
        $this->manager->markPaid($paid);
        $this->clock->modify('+1 hour');
        $fresh = $this->book($this->billboardA, '2026-10');

        $this->clock->modify('+23 hours 30 minutes'); // unpaid is 24.5h old, fresh is 23.5h old
        self::assertSame(1, $this->manager->expireOverdueHolds());

        self::assertSame(BookingStatus::Expired, $unpaid->getStatus());
        self::assertSame(BookingStatus::Paid, $paid->getStatus());
        self::assertSame(BookingStatus::Hold, $fresh->getStatus());
    }

    public function testOccupancyGrid(): void
    {
        $this->book($this->screen, '2026-09', slots: 3);
        $this->book($this->screen, '2026-09', months: 2, slots: 2);
        $this->bookDays($this->screen, '2026-10-05', '2026-10-18', 1);

        $columns = [];
        foreach (['2026-09', '2026-10', '2026-11'] as $month) {
            $columns[$month] = [new \DateTimeImmutable($month.'-01'), new \DateTimeImmutable($month.'-01 last day of this month')];
        }
        $columns['2026-10-19'] = [new \DateTimeImmutable('2026-10-19'), new \DateTimeImmutable('2026-10-19')];
        $grid = $this->manager->occupancy($this->screen->getProduct(), $columns)[$this->screen->getId()];

        self::assertSame(5, $grid['2026-09']['used']);
        self::assertSame(3, $grid['2026-10']['used']); // busiest day: 2 slots all month + 1 from the 5th to the 18th
        self::assertSame(2, $grid['2026-10-19']['used']);
        self::assertSame(0, $grid['2026-11']['used']);
        self::assertCount(2, $grid['2026-09']['bookings']);
    }

    public function testAirtimeIsBookedByDaysForTwoWeeksAtLeast(): void
    {
        $booking = $this->bookDays($this->screen, '2026-09-12', '2026-09-25', 2);

        self::assertEquals(new \DateTimeImmutable('2026-09-12'), $booking->getStartDate());
        self::assertEquals(new \DateTimeImmutable('2026-09-25'), $booking->getEndDate());
        self::assertSame(14, $booking->getDays());
        self::assertFalse($booking->isWholeMonths());

        $this->assertUnavailable(fn () => $this->bookDays($this->screen, '2026-09-12', '2026-09-24', 1), 'Минимальное размещение — 14 дней');
        $this->assertUnavailable(fn () => $this->bookDays($this->screen, '2026-09-09', '2026-09-30', 1), 'Первый день брони уже прошёл');
    }

    public function testBlockIsCheckedForEveryDay(): void
    {
        // 10 slots taken on 12–25 September and 10 on 26 September – 9 October: never more than 10 on one day
        foreach (range(1, 5) as $i) {
            $this->bookDays($this->screen, '2026-09-12', '2026-09-25', 2, 'Первые две недели '.$i);
            $this->bookDays($this->screen, '2026-09-26', '2026-10-09', 2, 'Вторые две недели '.$i);
        }

        // so 2 slots for the whole period fit (a sum over the period would say 20 are taken)
        self::assertInstanceOf(Booking::class, $this->bookDays($this->screen, '2026-09-12', '2026-10-09', 2));

        // now the block is full on every day from 12 September to 9 October, but free after
        $this->assertUnavailable(fn () => $this->bookDays($this->screen, '2026-10-05', '2026-10-18', 1), 'На 5 октября 2026 у стороны A свободно слотов: 0 из 12');
        self::assertInstanceOf(Booking::class, $this->bookDays($this->screen, '2026-10-10', '2026-10-23', 1));
    }

    public function testFullPeriodsAreWhenEverySlotIsTaken(): void
    {
        $this->screen->setSlotCount(2);
        $this->em->flush();
        $this->bookDays($this->screen, '2026-09-12', '2026-09-25', 1);
        $this->bookDays($this->screen, '2026-09-20', '2026-10-10', 1, 'Второй');
        $this->bookDays($this->screen, '2026-10-05', '2026-10-20', 1, 'Третий');

        $bookings = $this->em->getRepository(Booking::class)->findBy(['side' => $this->screen]);
        $periods = BookingManager::fullPeriods($this->screen, $bookings, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-31'));

        self::assertSame(
            [['2026-09-20', '2026-09-25'], ['2026-10-05', '2026-10-10']],
            array_map(static fn (array $p) => [$p[0]->format('Y-m-d'), $p[1]->format('Y-m-d')], $periods),
        );
        // a period cut by the end of the range
        $cut = BookingManager::fullPeriods($this->screen, $bookings, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-22'));
        self::assertSame(['2026-09-20', '2026-09-22'], [$cut[0][0]->format('Y-m-d'), $cut[0][1]->format('Y-m-d')]);
    }

    public function testWholeMonthOnAirtimeCountsEveryDay(): void
    {
        $this->bookDays($this->screen, '2026-09-17', '2026-09-30', 2);
        foreach (range(1, 5) as $i) {
            $this->book($this->screen, '2026-09', slots: 2, client: 'Месяц '.$i);
        }

        // the 17th–30th are full, a month-long booking would need them
        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', slots: 1), 'На 17 сентября 2026');
        self::assertInstanceOf(Booking::class, $this->bookDays($this->screen, '2026-10-01', '2026-10-14', 2));
    }

    public function testSidesOfOneStructureAreBookedByTheirOwnType(): void
    {
        // a static structure with a video screen on side B
        $this->billboardB->setProductType($this->em->getRepository(ProductType::class)->findOneBy(['name' => 'Видеоэкран']));
        $this->em->flush();
        self::assertFalse($this->billboardA->isAirtime());
        self::assertTrue($this->billboardB->isAirtime());

        // side B sells slots: clients share its block instead of taking the whole side
        self::assertSame(3, $this->book($this->billboardB, '2026-09', slots: 3, client: 'Кафе')->getSlots());
        self::assertSame(1, $this->bookDays($this->billboardB, '2026-09-12', '2026-09-25', 1, client: 'Салон')->getSlots());
        // without slots a screen side can't be booked (it would take the whole block)
        $this->assertUnavailable(fn () => $this->book($this->billboardB, '2026-10', client: 'Без слотов'), 'укажите число слотов');

        // side A is still booked whole for the month, slots are ignored
        $wholeSide = $this->book($this->billboardA, '2026-09', slots: 3);
        self::assertNull($wholeSide->getSlots());
        self::assertSame('2026-09-30', $wholeSide->getEndDate()->format('Y-m-d'));
        $this->assertUnavailable(fn () => $this->book($this->billboardA, '2026-09', client: 'Другой'), 'Сторона A уже забронирована');
    }

    private function bookDays(ProductSide $side, string $from, string $to, int $slots, string $client = 'ООО Ромашка'): Booking
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startDate = new \DateTimeImmutable($from);
        $request->endDate = new \DateTimeImmutable($to);
        $request->slots = $slots;
        $request->client = $this->clientAccount($client);
        $request->clientPhone = '+7 900 000-00-00';

        return $this->manager->hold($request);
    }

    private function book(ProductSide $side, string $month, int $months = 1, ?int $slots = null, string $client = 'ООО Ромашка'): Booking
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startMonth = $month;
        $request->months = $months;
        $request->slots = $slots;
        $request->client = $this->clientAccount($client);
        $request->clientPhone = '+7 900 000-00-00';

        return $this->manager->hold($request);
    }

    /** The client card a booking is made for, created on first use */
    private function clientAccount(string $title): User
    {
        $client = $this->em->getRepository(User::class)->findOneBy(['company' => $title]);
        if (null === $client) {
            $client = (new User())->setEmail(uniqid('client').'@romashka.ru')->setName('Контакт '.$title)->setCompany($title)
                ->setRole(User::ROLE_CLIENT)->setPassword('x')->setEmailVerifiedAt($this->clock->now());
            $this->em->persist($client);
            $this->em->flush();
        }

        return $client;
    }

    private function assertUnavailable(callable $action, string $expectedMessage): void
    {
        try {
            $action();
            self::fail('Expected BookingException');
        } catch (BookingException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }
}
