<?php

namespace App\Service;

use App\Entity\Payment;
use App\Repository\PaymentRepository;

/**
 * Money of a period, planned against received, month by month.
 *
 * Planned — the payments of the calendar due in the month, whatever came of them: how many of them are paid
 * and how much is still owed (overdue once the date has passed). Received — the money that came in the month by the
 * date it was marked paid, whichever month the payment was due in: an old debt paid now counts now.
 */
class CashFlowReport
{
    public function __construct(
        private readonly PaymentRepository $payments,
    ) {
    }

    /**
     * @return array{
     *     totals: array{planned: float, plannedPaid: float, plannedUnpaid: float, overdue: float, received: float, receivedEarlier: float, gap: float, collection: int},
     *     flow: list<array{month: \DateTimeImmutable, planned: float, plannedPaid: float, plannedUnpaid: float, overdue: float, received: float, receivedEarlier: float, gap: float, collection: int, count: int, paidCount: int}>,
     * }
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
    {
        $months = [];
        foreach (MonthCalendar::between($from, $to) as $month) {
            $months[$month->format('Y-m')] = [
                'month' => $month, 'planned' => 0.0, 'plannedPaid' => 0.0, 'plannedUnpaid' => 0.0, 'overdue' => 0.0,
                'received' => 0.0, 'receivedEarlier' => 0.0, 'gap' => 0.0, 'collection' => 0, 'count' => 0, 'paidCount' => 0,
            ];
        }

        foreach ($this->payments->findDueBetween($from, $to, $now) as $payment) {
            $row = &$months[$payment->getDueDate()->format('Y-m')];
            $amount = (float) $payment->getAmount();
            $row['planned'] += $amount;
            ++$row['count'];
            if ($payment->isPaid()) {
                $row['plannedPaid'] += $amount;
                ++$row['paidCount'];
            } else {
                $row['plannedUnpaid'] += $amount;
                if ($payment->daysOverdueAt($now) > 0) {
                    $row['overdue'] += $amount;
                }
            }
            unset($row);
        }

        foreach ($this->payments->findPaidBetween($from, $to) as $payment) {
            $row = &$months[$payment->getPaidAt()->format('Y-m')];
            $amount = (float) $payment->getAmount();
            $row['received'] += $amount;
            // money for a payment due before this month: a debt paid back
            if ($payment->getDueDate()->format('Y-m') < $row['month']->format('Y-m')) {
                $row['receivedEarlier'] += $amount;
            }
            unset($row);
        }

        $totals = ['planned' => 0.0, 'plannedPaid' => 0.0, 'plannedUnpaid' => 0.0, 'overdue' => 0.0, 'received' => 0.0, 'receivedEarlier' => 0.0];
        foreach ($months as &$row) {
            foreach ($totals as $key => $_) {
                $row[$key] = round($row[$key], 2);
                $totals[$key] += $row[$key];
            }
            $row['gap'] = round($row['received'] - $row['planned'], 2);
            $row['collection'] = self::percent($row['plannedPaid'], $row['planned']);
        }
        unset($row);

        $totals = array_map(static fn (float $sum) => round($sum, 2), $totals);
        $totals['gap'] = round($totals['received'] - $totals['planned'], 2);
        $totals['collection'] = self::percent($totals['plannedPaid'], $totals['planned']);

        return ['totals' => $totals, 'flow' => array_values($months)];
    }

    /** Share of the planned money that came, whole percent; 0 with nothing planned */
    private static function percent(float $part, float $whole): int
    {
        return $whole > 0 ? (int) round($part * 100 / $whole) : 0;
    }
}
