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

        foreach ([Booking::class, ProductSidePhoto::class, ProductSide::class, Product::class, ProductType::class, Category::class, District::class] as $class) {
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
        self::assertNull($booking->getClipDuration());
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

    public function testAirtimeLoopHoldsUpTo120Seconds(): void
    {
        $bookings = [];
        for ($i = 1; $i <= 8; ++$i) { // 8 × 15 s = 120 s
            $bookings[] = $this->book($this->screen, '2026-09', clip: 15, client: 'Клиент '.$i);
        }

        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', clip: 5), 'свободно 0 сек из 120');
        // the next month is a separate loop
        self::assertSame(5, $this->book($this->screen, '2026-10', clip: 5)->getClipDuration());

        $this->manager->cancel($bookings[0]); // frees 15 s
        $this->book($this->screen, '2026-09', clip: 10);
        $this->book($this->screen, '2026-09', clip: 5);
        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', clip: 5), 'свободно 0 сек');
    }

    public function testAirtimeReportsFreeSecondsWhenClipDoesNotFit(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->book($this->screen, '2026-09', clip: 15);
        }
        $this->book($this->screen, '2026-09', clip: 10); // 115 s used

        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', clip: 10), 'свободно 5 сек из 120 — ролик 10 сек не помещается');
        self::assertSame(5, $this->book($this->screen, '2026-09', clip: 5)->getClipDuration());
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
        $this->book($this->screen, '2026-09', clip: 15);
        $this->book($this->screen, '2026-09', months: 2, clip: 10);
        $this->bookDays($this->screen, '2026-10-05', '2026-10-06', 5);

        $columns = [];
        foreach (['2026-09', '2026-10', '2026-11'] as $month) {
            $columns[$month] = [new \DateTimeImmutable($month.'-01'), new \DateTimeImmutable($month.'-01 last day of this month')];
        }
        $columns['2026-10-07'] = [new \DateTimeImmutable('2026-10-07'), new \DateTimeImmutable('2026-10-07')];
        $grid = $this->manager->occupancy($this->screen->getProduct(), $columns)[$this->screen->getId()];

        self::assertSame(25, $grid['2026-09']['used']);
        self::assertSame(15, $grid['2026-10']['used']); // busiest day: 10 s all month + 5 s on the 5th and 6th
        self::assertSame(10, $grid['2026-10-07']['used']);
        self::assertSame(0, $grid['2026-11']['used']);
        self::assertCount(2, $grid['2026-09']['bookings']);
    }

    public function testAirtimeIsBookedByDays(): void
    {
        $booking = $this->bookDays($this->screen, '2026-09-12', '2026-09-18', 15);

        self::assertEquals(new \DateTimeImmutable('2026-09-12'), $booking->getStartDate());
        self::assertEquals(new \DateTimeImmutable('2026-09-18'), $booking->getEndDate());
        self::assertSame(7, $booking->getDays());
        self::assertFalse($booking->isWholeMonths());

        $this->assertUnavailable(fn () => $this->bookDays($this->screen, '2026-09-09', '2026-09-12', 5), 'Первый день брони уже прошёл');
    }

    public function testLoopIsCheckedForEveryDay(): void
    {
        // 105 s taken on the 12th–15th and 105 s on the 16th–20th: never more than 105 s on one day
        foreach (range(1, 7) as $i) {
            $this->bookDays($this->screen, '2026-09-12', '2026-09-15', 15, 'Первая неделя '.$i);
            $this->bookDays($this->screen, '2026-09-16', '2026-09-20', 15, 'Вторая неделя '.$i);
        }

        // so a 15 s clip for the whole period fits (a per-month sum would say 210 s are taken)
        self::assertInstanceOf(Booking::class, $this->bookDays($this->screen, '2026-09-12', '2026-09-20', 15));

        // now the loop is full on every day from the 12th to the 20th, but free before and after
        $this->assertUnavailable(fn () => $this->bookDays($this->screen, '2026-09-19', '2026-09-22', 5), 'На 19 сентября 2026 в петле стороны A свободно 0 сек из 120');
        self::assertInstanceOf(Booking::class, $this->bookDays($this->screen, '2026-09-21', '2026-09-22', 15));
        self::assertInstanceOf(Booking::class, $this->bookDays($this->screen, '2026-09-10', '2026-09-11', 15));
    }

    public function testWholeMonthOnAirtimeCountsEveryDay(): void
    {
        $this->bookDays($this->screen, '2026-09-25', '2026-09-25', 15);
        foreach (range(1, 7) as $i) {
            $this->book($this->screen, '2026-09', clip: 15, client: 'Месяц '.$i);
        }

        // the 25th is full, a month-long booking would need it
        $this->assertUnavailable(fn () => $this->book($this->screen, '2026-09', clip: 5), 'На 25 сентября 2026');
        self::assertInstanceOf(Booking::class, $this->bookDays($this->screen, '2026-09-26', '2026-09-30', 15));
    }

    public function testSidesOfOneStructureAreBookedByTheirOwnType(): void
    {
        // a static structure with a video screen on side B
        $this->billboardB->setProductType($this->em->getRepository(ProductType::class)->findOneBy(['name' => 'Видеоэкран']));
        $this->em->flush();
        self::assertFalse($this->billboardA->isAirtime());
        self::assertTrue($this->billboardB->isAirtime());

        // side B sells clips: clients share its loop instead of taking the whole side
        self::assertSame(15, $this->book($this->billboardB, '2026-09', clip: 15, client: 'Кафе')->getClipDuration());
        self::assertSame(5, $this->bookDays($this->billboardB, '2026-09-12', '2026-09-18', 5, client: 'Салон')->getClipDuration());
        // without a clip length a screen side can't be booked (it would take the whole loop)
        $this->assertUnavailable(fn () => $this->book($this->billboardB, '2026-10', client: 'Без ролика'), 'выберите длину ролика');

        // side A is still booked whole for the month, a clip length is ignored
        $wholeSide = $this->book($this->billboardA, '2026-09', clip: 15);
        self::assertNull($wholeSide->getClipDuration());
        self::assertSame('2026-09-30', $wholeSide->getEndDate()->format('Y-m-d'));
        $this->assertUnavailable(fn () => $this->book($this->billboardA, '2026-09', client: 'Другой'), 'Сторона A уже забронирована');
    }

    private function bookDays(ProductSide $side, string $from, string $to, int $clip, string $client = 'ООО Ромашка'): Booking
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startDate = new \DateTimeImmutable($from);
        $request->endDate = new \DateTimeImmutable($to);
        $request->clipDuration = $clip;
        $request->clientName = $client;
        $request->clientPhone = '+7 900 000-00-00';

        return $this->manager->hold($request);
    }

    private function book(ProductSide $side, string $month, int $months = 1, ?int $clip = null, string $client = 'ООО Ромашка'): Booking
    {
        $request = new BookingRequest();
        $request->side = $side;
        $request->startMonth = $month;
        $request->months = $months;
        $request->clipDuration = $clip;
        $request->clientName = $client;
        $request->clientPhone = '+7 900 000-00-00';

        return $this->manager->hold($request);
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
