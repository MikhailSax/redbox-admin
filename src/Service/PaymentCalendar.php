<?php

namespace App\Service;

use App\Entity\Payment;

/**
 * Month grid of the payment calendar: whole weeks from Monday, each day with the payments due on it.
 */
final class PaymentCalendar
{
    /**
     * @param list<Payment> $payments
     *
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, today: bool, payments: list<Payment>}>>
     */
    public static function weeks(\DateTimeImmutable $month, array $payments, \DateTimeImmutable $now): array
    {
        $byDay = [];
        foreach ($payments as $payment) {
            $byDay[$payment->getDueDate()->format('Y-m-d')][] = $payment;
        }

        [$first, $last] = self::gridRange($month);
        $today = $now->format('Y-m-d');
        $weeks = [];
        for ($i = 0, $day = $first; $day <= $last; ++$i, $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $weeks[intdiv($i, 7)][] = [
                'date' => $day,
                'inMonth' => $day->format('Y-m') === $month->format('Y-m'),
                'today' => $key === $today,
                'payments' => $byDay[$key] ?? [],
            ];
        }

        return $weeks;
    }

    /**
     * First and last day shown for the month: from the Monday of its first week to the Sunday of its last one.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function gridRange(\DateTimeImmutable $month): array
    {
        $firstOfMonth = MonthCalendar::firstDay($month);
        $lastOfMonth = MonthCalendar::lastDay($month);

        return [
            $firstOfMonth->modify(\sprintf('-%d days', (int) $firstOfMonth->format('N') - 1)),
            $lastOfMonth->modify(\sprintf('+%d days', 7 - (int) $lastOfMonth->format('N'))),
        ];
    }
}
