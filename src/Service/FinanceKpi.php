<?php

namespace App\Service;

use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Money tiles of the dashboard: sales, shipments and money received this month, against the previous month and the
 * same month a year ago, and what clients owe.
 *
 * Sales and money received are counted from the 1st to today and compared with the same days of the other months,
 * so a month half gone is not set against a whole one. Shipments are known for the whole month ahead (the bookings
 * are already in place), so whole months are compared.
 */
class FinanceKpi
{
    public function __construct(
        private readonly SalesReport $sales,
        private readonly ShipmentReport $shipments,
        private readonly PaymentRepository $payments,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     month: \DateTimeImmutable,
     *     day: int,
     *     tiles: list<array{key: string, label: string, value: float, previous: float, yearAgo: float, vsPrevious: ?int, vsYearAgo: ?int, hint: string}>,
     *     debt: array{total: float, overdue: float, overdueCount: int},
     * }
     */
    public function overview(): array
    {
        $now = $this->clock->now();
        $month = MonthCalendar::firstDay($now);
        $day = (int) $now->format('j');
        $periods = [
            'current' => $month,
            'previous' => $month->modify('-1 month'),
            'yearAgo' => $month->modify('-1 year'),
        ];

        // the same days of each month: 1st to today's day (or the month's last day, when shorter)
        $toDay = static fn (\DateTimeImmutable $m) => min(MonthCalendar::dayOf($m, $day), MonthCalendar::lastDay($m));

        $sales = array_map(fn (\DateTimeImmutable $m) => (float) $this->sales->build($m, $toDay($m))['totals']['total'], $periods);
        $received = array_map(fn (\DateTimeImmutable $m) => $this->payments->sumPaidBetween($m, $toDay($m)), $periods);
        $shipped = array_map(fn (\DateTimeImmutable $m) => (float) $this->shipments->build($m, MonthCalendar::lastDay($m))['totals']['total'], $periods);

        $overdue = $this->payments->totals(PaymentStatus::Overdue, $now);

        return [
            'month' => $month,
            'day' => $day,
            'tiles' => [
                self::tile('sales', 'Продажи', $sales, \sprintf('с 1 по %d число', $day)),
                self::tile('shipments', 'Отгрузка', $shipped, 'за весь месяц'),
                self::tile('received', 'Поступило денег', $received, \sprintf('с 1 по %d число', $day)),
            ],
            'debt' => [
                'total' => round($this->payments->sumUnpaid(), 2),
                'overdue' => round($overdue['sum'], 2),
                'overdueCount' => $overdue['count'],
            ],
        ];
    }

    /**
     * @param array{current: float, previous: float, yearAgo: float} $values
     *
     * @return array{key: string, label: string, value: float, previous: float, yearAgo: float, vsPrevious: ?int, vsYearAgo: ?int, hint: string}
     */
    private static function tile(string $key, string $label, array $values, string $hint): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => round($values['current'], 2),
            'previous' => round($values['previous'], 2),
            'yearAgo' => round($values['yearAgo'], 2),
            'vsPrevious' => self::change($values['current'], $values['previous']),
            'vsYearAgo' => self::change($values['current'], $values['yearAgo']),
            'hint' => $hint,
        ];
    }

    /** Change against the base, whole percent; null when there is nothing to compare with */
    public static function change(float $value, float $base): ?int
    {
        return $base > 0 ? (int) round(($value - $base) * 100 / $base) : null;
    }
}
