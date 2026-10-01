<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Enum\PaymentStatus;
use App\Repository\BookingRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Service\Availability\AvailabilityResolver;
use App\Service\Availability\ProductAvailability;
use App\Service\Availability\SideAvailability;
use Symfony\Component\Clock\ClockInterface;

/**
 * Numbers for the "Обзор" dashboard.
 *
 * Billboards (sides sold whole) and video screens (airtime) are counted apart: a billboard side is free, booked
 * or occupied, a screen is loaded by the seconds of its block sold on the busiest day of the month.
 */
class DashboardStats
{
    private const FORECAST_MONTHS = 6;

    public function __construct(
        private readonly AvailabilityResolver $availability,
        private readonly BookingRepository $bookings,
        private readonly PaymentRepository $payments,
        private readonly ProductRepository $products,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     month: \DateTimeImmutable,
     *     billboards: list<array{month: \DateTimeImmutable, sides: int, free: int, booked: int, occupied: int}>,
     *     screens: list<array{month: \DateTimeImmutable, screens: int, seconds: int, occupied: int, booked: int, percent: int}>,
     *     screenLoad: list<array{productId: int, product: string, side: string, percent: int, usedSlots: string, slotCount: int, confirmedSeconds: int, holdSeconds: int, blockSeconds: int}>,
     *     holds: list<Booking>,
     *     holdCount: int,
     *     payments: array{urgent: list<Payment>, overdue: array{count: int, sum: float}, soon: array{count: int, sum: float}},
     * }
     */
    public function overview(): array
    {
        $now = $this->clock->now();
        $months = MonthCalendar::range($now, self::FORECAST_MONTHS);

        // What is left to sell in the coming months, billboards and screens apart; the first month is the current one
        $billboards = [];
        $screens = [];
        $current = [];
        foreach ($months as $i => $month) {
            $products = $this->availability->forProducts(null, $month);
            if (0 === $i) {
                $current = $products;
            }

            $billboard = ['month' => $month, 'sides' => 0, 'free' => 0, 'booked' => 0, 'occupied' => 0];
            $screen = ['month' => $month, 'screens' => 0, 'seconds' => 0, 'occupied' => 0, 'booked' => 0, 'percent' => 0];
            foreach ($products as $product) {
                foreach ($product->sides as $side) {
                    if ($side->airtime) {
                        ++$screen['screens'];
                        $screen['seconds'] += $side->blockSeconds();
                        $screen['occupied'] += self::confirmed($side);
                        $screen['booked'] += $side->usedSeconds() - self::confirmed($side);
                    } else {
                        ++$billboard['sides'];
                        ++$billboard[$side->status()->value];
                    }
                }
            }
            $screen['percent'] = $screen['screens'] > 0 ? (int) round(($screen['occupied'] + $screen['booked']) * 100 / $screen['seconds']) : 0;
            $billboards[] = $billboard;
            $screens[] = $screen;
        }

        return [
            'month' => $months[0],
            'billboards' => $billboards,
            'screens' => $screens,
            'screenLoad' => $this->screenLoad($current),
            'holds' => $this->bookings->findLiveHolds($now, 6),
            'holdCount' => $this->bookings->countLiveHolds($now),
            // what clients must pay now: overdue first, then due within PaymentStatus::SOON_DAYS
            'payments' => [
                'urgent' => array_slice([...$this->payments->findOverdue($now, 6), ...$this->payments->findDueSoon($now, 6)], 0, 6),
                'overdue' => $this->payments->totals(PaymentStatus::Overdue, $now),
                'soon' => $this->payments->totals(PaymentStatus::DueSoon, $now),
            ],
        ];
    }

    /**
     * Every screen with its load this month, the busiest first.
     *
     * @param array<int, ProductAvailability> $products
     *
     * @return list<array{productId: int, product: string, side: string, percent: int, usedSlots: string, slotCount: int, confirmedSeconds: int, holdSeconds: int, blockSeconds: int}>
     */
    private function screenLoad(array $products): array
    {
        $screens = [];
        foreach ($products as $product) {
            foreach ($product->sides as $side) {
                if ($side->airtime) {
                    $screens[] = [$product->productId, $side];
                }
            }
        }
        if ([] === $screens) {
            return [];
        }

        $names = [];
        foreach ($this->products->findBy(['id' => array_unique(array_column($screens, 0))]) as $entity) {
            $names[$entity->getId()] = (string) $entity->getName();
        }

        $rows = array_map(static fn (array $screen) => [
            'productId' => $screen[0],
            'product' => $names[$screen[0]] ?? '',
            'side' => $screen[1]->sideName,
            'percent' => $screen[1]->loadPercent(),
            'usedSlots' => $screen[1]->usedSlotsLabel(),
            'slotCount' => $screen[1]->slotCount,
            'confirmedSeconds' => self::confirmed($screen[1]),
            'holdSeconds' => $screen[1]->usedSeconds() - self::confirmed($screen[1]),
            'blockSeconds' => $screen[1]->blockSeconds(),
        ], $screens);
        usort($rows, static fn (array $a, array $b) => [$b['percent'], $a['product'], $a['side']] <=> [$a['percent'], $b['product'], $b['side']]);

        return $rows;
    }

    /** Seconds sold for sure, never more than the block */
    private static function confirmed(SideAvailability $side): int
    {
        return min($side->blockSeconds(), $side->confirmedSeconds);
    }
}
