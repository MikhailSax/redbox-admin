<?php

namespace App\Service\Availability;

use App\Entity\Booking;
use App\Entity\ProductSide;
use App\Enum\BookingMode;
use App\Enum\BookingStatus;
use App\Service\BookingManager;
use App\Service\MonthCalendar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Computes free / booked / occupied status of structures for a month from their active bookings.
 * Status is derived on the fly (never stored), so expiring holds and month changes are always reflected.
 *
 * For the current month only the days from today on count. Whole sides are taken by any booking in the period;
 * airtime sides when every slot is taken on the busiest day of the period (bookings are by days, so the load
 * differs from day to day).
 */
class AvailabilityResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int>|null $productIds null = every structure
     *
     * @return array<int, ProductAvailability> keyed by product id, in the order of $productIds
     */
    public function forProducts(?array $productIds, \DateTimeInterface $month): array
    {
        if ([] === $productIds) {
            return [];
        }

        $now = $this->clock->now();
        $month = MonthCalendar::firstDay($month);
        $from = max($month, $now->setTime(0, 0));
        $to = MonthCalendar::lastDay($month);

        $sides = $this->entityManager->createQueryBuilder()
            // a side's own type wins over the structure's (a screen on one side, a static poster on another)
            ->select('p.id AS productId', 's.id AS sideId', 's.name AS sideName', 's.slotCount AS slotCount', 'COALESCE(st.bookingMode, t.bookingMode) AS mode')
            ->from(ProductSide::class, 's')
            ->join('s.product', 'p')
            ->join('p.productType', 't')
            ->leftJoin('s.productType', 'st')
            ->orderBy('s.name', 'ASC');
        if (null !== $productIds) {
            $sides->andWhere('p.id IN (:ids)')->setParameter('ids', $productIds);
        }
        $sides = $sides->getQuery()->getArrayResult();

        // Active bookings (paid, or holds not yet overdue) in the period, grouped by side
        $bookingsBySide = [];
        if ($from <= $to && [] !== $sides) {
            $bookings = $this->entityManager->createQueryBuilder()
                ->select('b', 's')
                ->from(Booking::class, 'b')
                ->join('b.side', 's')
                ->andWhere('s.id IN (:sides)')
                ->andWhere('b.startDate <= :to AND b.endDate >= :from')
                ->andWhere('(b.status = :paid OR (b.status = :hold AND b.expiresAt > :now))')
                ->setParameter('sides', array_column($sides, 'sideId'))
                ->setParameter('from', $from, 'date_immutable')
                ->setParameter('to', $to, 'date_immutable')
                ->setParameter('paid', BookingStatus::Paid)
                ->setParameter('hold', BookingStatus::Hold)
                ->setParameter('now', $now)
                ->getQuery()
                ->getResult();
            foreach ($bookings as $booking) {
                $bookingsBySide[$booking->getSide()->getId()][] = $booking;
            }
        }

        $sidesByProduct = [];
        foreach ($sides as $row) {
            $mode = $row['mode'] instanceof BookingMode ? $row['mode'] : BookingMode::from($row['mode']);
            $airtime = BookingMode::Airtime === $mode;
            [$paid, $hold] = self::load($bookingsBySide[(int) $row['sideId']] ?? [], $airtime, $from, $to);

            $sidesByProduct[(int) $row['productId']][] = new SideAvailability((int) $row['sideId'], (string) $row['sideName'], $airtime, $paid, $hold, $airtime ? (int) $row['slotCount'] : 1);
        }

        $result = [];
        foreach ($productIds ?? array_keys($sidesByProduct) as $productId) {
            $result[$productId] = new ProductAvailability($productId, $month, $sidesByProduct[$productId] ?? []);
        }

        return $result;
    }

    public function forProduct(int $productId, \DateTimeInterface $month): ProductAvailability
    {
        return $this->forProducts([$productId], $month)[$productId];
    }

    /**
     * Slots taken by paid bookings and by holds: on the busiest day for airtime,
     * 1 of 1 for any booking of a whole side.
     *
     * @param list<Booking> $bookings
     *
     * @return array{0: int, 1: int} [paid slots, hold slots]
     */
    private static function load(array $bookings, bool $airtime, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $paid = array_values(array_filter($bookings, static fn (Booking $b) => BookingStatus::Paid === $b->getStatus()));
        $hold = array_values(array_filter($bookings, static fn (Booking $b) => BookingStatus::Hold === $b->getStatus()));

        if (!$airtime) {
            return [[] !== $paid ? 1 : 0, [] !== $hold ? 1 : 0];
        }

        $peak = BookingManager::peak($bookings, $from, $to);
        $onPeakDay = static fn (array $list) => BookingManager::peak(array_values(array_filter($list, static fn (Booking $b) => $b->covers($peak['day']))), $peak['day'], $peak['day'])['used'];

        return [$onPeakDay($paid), $onPeakDay($hold)];
    }
}
