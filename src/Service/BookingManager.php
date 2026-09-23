<?php

namespace App\Service;

use App\Dto\BookingRequest;
use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Booking rules:
 *  - every booking is made for a client card (BookingRequest::$client): that's who the side is taken by;
 *  - the side's type decides (sides of one structure may differ: a screen and a static poster);
 *  - whole sides: a side is booked for whole months, one active booking at a time;
 *  - airtime sides (video): sold by days; the screen's block has a number of slots (ProductSide::$slotCount,
 *    12 by default) and a booking takes one or more of them on every day; the screen is taken when every slot is;
 *  - nothing is placed for less than two weeks (BookingMode::MIN_DAYS);
 *  - a new booking is a hold that blocks the slot for 24 hours; unpaid holds stop
 *    blocking at the deadline and are marked Expired by the scheduled cleanup.
 */
class BookingManager
{
    public const HOLD_TTL = '+24 hours';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Creates a 24h hold if the side is free for the whole period.
     *
     * @throws BookingException when the side or the airtime is already taken
     */
    public function hold(BookingRequest $request, ?User $createdBy = null): Booking
    {
        $side = $request->side;
        if (null === $request->client) {
            throw new BookingException('Выберите клиента — бронь закрепляется за карточкой клиента.');
        }
        [$start, $end] = $request->period();
        $slots = $this->isAirtime($side) ? $request->slots : null;
        if ($this->isAirtime($side) && (null === $slots || $slots < 1 || $slots > $side->getSlotCount())) {
            // e.g. a media plan item added before the side became a screen: without slots it would take the whole block
            throw new BookingException(\sprintf('Сторона %s продаётся эфиром — укажите число слотов: от 1 до %d.', $side->getName(), $side->getSlotCount()));
        }
        if (MonthCalendar::days($start, $end) < BookingMode::MIN_DAYS) {
            throw new BookingException(\sprintf('Минимальное размещение — %d дней.', BookingMode::MIN_DAYS));
        }

        // Serialises bookings of the same side, so two managers can't take the last slot at once.
        $lock = $this->lockFactory->createLock('booking-side-'.$side->getId(), 30);
        $lock->acquire(true);

        try {
            $now = $this->clock->now();
            if ($request->isByDays() && $start < $now->setTime(0, 0)) {
                throw new BookingException('Первый день брони уже прошёл — выберите сегодня или позже.');
            }
            $this->assertAvailable($side, $start, $end, $slots, $now);

            $booking = new Booking($side, $start, $end, $slots, $request->client, $request->contactName(), $request->contactPhone(), $request->comment, $createdBy);
            $booking->hold($now->modify(self::HOLD_TTL));

            $this->entityManager->persist($booking);
            $this->entityManager->flush();

            return $booking;
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws BookingException
     */
    public function markPaid(Booking $booking): void
    {
        $now = $this->clock->now();
        if ($booking->isHoldOverdue($now)) {
            throw new BookingException('Срок брони истёк — место могли занять. Создайте новую бронь.');
        }
        if (BookingStatus::Hold !== $booking->getStatus()) {
            throw new BookingException('Оплатить можно только бронь, которая ждёт оплаты.');
        }

        $booking->markPaid($now);
        $this->entityManager->flush();
    }

    /**
     * @throws BookingException
     */
    public function cancel(Booking $booking): void
    {
        if (!$booking->isActiveAt($this->clock->now())) {
            throw new BookingException('Эта бронь уже не действует.');
        }

        $booking->cancel();
        $this->entityManager->flush();
    }

    /**
     * Marks unpaid holds past their deadline as Expired. Run every few minutes by the scheduler.
     *
     * @return int number of expired holds
     */
    public function expireOverdueHolds(): int
    {
        $overdue = $this->bookings->findOverdueHolds($this->clock->now());
        foreach ($overdue as $booking) {
            $booking->expire();
        }
        $this->entityManager->flush();

        return \count($overdue);
    }

    /**
     * Occupancy of each side of the product per column (a month or a day of the grid).
     * "used" is the slots of the block taken on the busiest day of the column.
     *
     * @param array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $columns key => [first day, last day]
     *
     * @return array<int, array<string, array{bookings: list<Booking>, used: int}>> [sideId][column key]
     */
    public function occupancy(Product $product, array $columns): array
    {
        $grid = [];
        foreach ($product->getSides() as $side) {
            foreach ($columns as $key => $period) {
                $grid[$side->getId()][$key] = ['bookings' => [], 'used' => 0];
            }
        }
        if ([] === $columns) {
            return $grid;
        }

        $first = reset($columns)[0];
        $last = end($columns)[1];
        $active = $this->bookings->findActiveForProduct($product, $first, $last, $this->clock->now());
        foreach ($product->getSides() as $side) {
            $sideBookings = array_values(array_filter($active, static fn (Booking $b) => $b->getSide() === $side));
            foreach ($columns as $key => [$from, $to]) {
                $inColumn = array_values(array_filter($sideBookings, static fn (Booking $b) => $b->overlaps($from, $to)));
                $grid[$side->getId()][$key] = ['bookings' => $inColumn, 'used' => self::peak($inColumn, $from, $to)['used']];
            }
        }

        return $grid;
    }

    /**
     * The busiest day of [$from, $to]: slots of the block taken by $bookings on it
     * (a whole-side booking takes the whole block).
     *
     * @param list<Booking> $bookings
     *
     * @return array{day: \DateTimeImmutable, used: int}
     */
    public static function peak(array $bookings, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $peak = ['day' => $from, 'used' => 0];
        foreach (self::load($bookings, $from, $to) as $day => $used) {
            if ($used > $peak['used']) {
                $peak = ['day' => new \DateTimeImmutable($day), 'used' => $used];
            }
        }

        return $peak;
    }

    /**
     * Periods within [$from, $to] when every slot of the side is taken: what a client sees as "busy".
     *
     * @param list<Booking> $bookings active bookings of the side
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> [first day, last day]
     */
    public static function fullPeriods(ProductSide $side, array $bookings, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $periods = [];
        $start = null;
        foreach (self::load($bookings, $from, $to) as $day => $used) {
            $day = new \DateTimeImmutable($day);
            if ($used >= $side->getSlotCount()) {
                $start ??= $day;
            } elseif (null !== $start) {
                $periods[] = [$start, $day->modify('-1 day')];
                $start = null;
            }
        }

        // the load drops back to 0 the day after the last booking (clipped to $to), which closes every period
        return $periods;
    }

    /**
     * Slots taken from each day on where the load changes: +slots on the first day, -slots the day after the last one.
     *
     * @param list<Booking> $bookings
     *
     * @return array<string, int> "Y-m-d" => slots taken from that day on, in date order
     */
    private static function load(array $bookings, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $changes = [];
        foreach ($bookings as $booking) {
            $slots = $booking->getSide()->slotsTakenBy($booking);
            $start = max($booking->getStartDate(), $from)->format('Y-m-d');
            $end = min($booking->getEndDate(), $to)->modify('+1 day')->format('Y-m-d');
            if ($start < $end) {
                $changes[$start] = ($changes[$start] ?? 0) + $slots;
                $changes[$end] = ($changes[$end] ?? 0) - $slots;
            }
        }
        ksort($changes);

        $load = [];
        $used = 0;
        foreach ($changes as $day => $change) {
            $load[$day] = $used += $change;
        }

        return $load;
    }

    /**
     * Why the side can't be booked for the days [$start, $end] right now, or null when it can.
     * A read-only check (no lock): the real booking re-checks under the lock.
     */
    public function availabilityProblem(ProductSide $side, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $slots): ?string
    {
        try {
            $this->assertAvailable($side, $start, $end, $this->isAirtime($side) ? max(1, (int) $slots) : null, $this->clock->now());

            return null;
        } catch (BookingException $e) {
            return $e->getMessage();
        }
    }

    public function isAirtime(ProductSide $side): bool
    {
        return $side->isAirtime();
    }

    /**
     * @throws BookingException
     */
    private function assertAvailable(ProductSide $side, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $slots, \DateTimeImmutable $now): void
    {
        $existing = $this->bookings->findActiveOverlapping($side, $start, $end, $now);

        if (null === $slots) {
            if ([] !== $existing) {
                $taken = $existing[0];
                throw new BookingException(\sprintf('Сторона %s уже забронирована: %s (%s).', $side->getName(), MonthCalendar::periodLabel($taken->getStartDate(), $taken->getEndDate()), $taken->getClientTitle()));
            }

            return;
        }

        // Every day of the period must have room for the slots; checking the busiest one is enough.
        // A whole-side booking on an airtime side (e.g. type switched later) takes the whole block.
        $peak = self::peak($existing, $start, $end);
        if ($peak['used'] + $slots > $side->getSlotCount()) {
            throw new BookingException(\sprintf(
                'На %s у стороны %s свободно слотов: %d из %d, а нужно %d.',
                MonthCalendar::periodLabel($peak['day'], $peak['day']), $side->getName(), max(0, $side->getSlotCount() - $peak['used']), $side->getSlotCount(), $slots,
            ));
        }
    }
}
