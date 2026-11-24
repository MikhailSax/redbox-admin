<?php

namespace App\Service;

use App\Entity\Payment;
use App\Entity\User;
use App\Enum\ClientType;
use App\Repository\PaymentRepository;

/**
 * Receivables as of today: what every client still has to pay, by how late it is.
 * A debt is a payment of the calendar not paid yet; the part past its due date is overdue,
 * aged by days late: up to 30, 31–60, 61–90 and more than 90.
 */
class DebtReport
{
    /** Upper bounds of the age buckets, days late; the last bucket has none */
    public const BUCKETS = ['d30' => 30, 'd60' => 60, 'd90' => 90, 'older' => null];

    public const BUCKET_LABELS = ['d30' => 'до 30 дней', 'd60' => '31–60 дней', 'd90' => '61–90 дней', 'older' => 'больше 90 дней'];

    public function __construct(
        private readonly PaymentRepository $payments,
    ) {
    }

    /**
     * @return array{
     *     totals: array{clients: int, payments: int, debt: float, current: float, overdue: float, overdueClients: int, d30: float, d60: float, d90: float, older: float},
     *     clients: list<array{client: User, payments: int, debt: float, current: float, overdue: float, d30: float, d60: float, d90: float, older: float, maxDays: int, nextDue: ?\DateTimeImmutable}>,
     *     overdue: list<array{payment: Payment, days: int}>,
     * }
     */
    public function build(\DateTimeImmutable $now, ?ClientType $clientType = null): array
    {
        $empty = ['debt' => 0.0, 'current' => 0.0, 'overdue' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'older' => 0.0];
        $totals = ['clients' => 0, 'payments' => 0, 'overdueClients' => 0] + $empty;
        $clients = [];
        $overdue = [];

        foreach ($this->payments->findUnpaid($clientType) as $payment) {
            $amount = (float) $payment->getAmount();
            $days = $payment->daysOverdueAt($now);
            $client = $payment->getClient();

            $row = &$clients[$client->getId()];
            $row ??= ['client' => $client, 'payments' => 0, 'maxDays' => 0, 'nextDue' => null] + $empty;
            ++$row['payments'];
            $row['debt'] += $amount;
            ++$totals['payments'];
            $totals['debt'] += $amount;

            if ($days > 0) {
                $bucket = self::bucket($days);
                $row['overdue'] += $amount;
                $row[$bucket] += $amount;
                $row['maxDays'] = max($row['maxDays'], $days);
                $totals['overdue'] += $amount;
                $totals[$bucket] += $amount;
                $overdue[] = ['payment' => $payment, 'days' => $days];
            } else {
                $row['current'] += $amount;
                $totals['current'] += $amount;
                // payments come the earliest first: the first one not late is the next due
                $row['nextDue'] ??= $payment->getDueDate();
            }
            unset($row);
        }

        $clients = array_values($clients);
        // the most overdue first, then the biggest debt
        usort($clients, static fn (array $a, array $b) => [$b['overdue'], $b['debt']] <=> [$a['overdue'], $a['debt']]);
        usort($overdue, static fn (array $a, array $b) => $b['days'] <=> $a['days']);

        $totals['clients'] = \count($clients);
        $totals['overdueClients'] = \count(array_filter($clients, static fn (array $row) => $row['overdue'] > 0));
        foreach ($empty as $key => $_) {
            $totals[$key] = round($totals[$key], 2);
        }

        return ['totals' => $totals, 'clients' => $clients, 'overdue' => $overdue];
    }

    /** @return key-of<self::BUCKETS> */
    public static function bucket(int $days): string
    {
        foreach (self::BUCKETS as $key => $limit) {
            if (null === $limit || $days <= $limit) {
                return $key;
            }
        }

        return 'older';
    }
}
