<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Enum\PaymentStatus;
use App\Repository\BookingRepository;
use App\Repository\PaymentRepository;
use App\Service\Availability\AvailabilityResolver;
use Symfony\Component\Clock\ClockInterface;

/**
 * Numbers for the "Обзор" dashboard.
 */
class DashboardStats
{
    private const FORECAST_MONTHS = 6;

    public function __construct(
        private readonly AvailabilityResolver $availability,
        private readonly BookingRepository $bookings,
        private readonly PaymentRepository $payments,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     month: \DateTimeImmutable,
     *     structures: array{total: int, free: int, booked: int, occupied: int},
     *     forecast: list<array{month: \DateTimeImmutable, sides: int, free: int, booked: int, occupied: int}>,
     *     holds: list<Booking>,
     *     holdCount: int,
     *     payments: array{urgent: list<Payment>, overdue: array{count: int, sum: float}, soon: array{count: int, sum: float}},
     * }
     */
    public function overview(): array
    {
        $now = $this->clock->now();
        $months = MonthCalendar::range($now, self::FORECAST_MONTHS);

        $structures = ['total' => 0, 'free' => 0, 'booked' => 0, 'occupied' => 0];
        foreach ($this->availability->forProducts(null, $months[0]) as $product) {
            if (null !== $status = $product->status()) {
                ++$structures['total'];
                ++$structures[$status->value];
            }
        }

        // Sold share of all sides per month: what is left to sell in the coming months
        $forecast = [];
        foreach ($months as $month) {
            $row = ['month' => $month, 'sides' => 0, 'free' => 0, 'booked' => 0, 'occupied' => 0];
            foreach ($this->availability->forProducts(null, $month) as $product) {
                foreach ($product->sides as $side) {
                    ++$row['sides'];
                    ++$row[$side->status()->value];
                }
            }
            $forecast[] = $row;
        }

        return [
            'month' => $months[0],
            'structures' => $structures,
            'forecast' => $forecast,
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
}
