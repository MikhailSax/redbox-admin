<?php

namespace App\Service;

use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Calendar helpers: months as first-day DateTimeImmutable values (static sides are sold per month)
 * and inclusive day periods (airtime is sold per day).
 */
class MonthCalendar
{
    /**
     * "2026-09" => 2026-09-01 00:00.
     */
    public static function parse(string $yearMonth): \DateTimeImmutable
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
        if (false === $month || $month->format('Y-m') !== $yearMonth) {
            throw new \InvalidArgumentException(\sprintf('Invalid month "%s", expected YYYY-MM.', $yearMonth));
        }

        return $month;
    }

    public static function firstDay(\DateTimeInterface $date): \DateTimeImmutable
    {
        return self::parse($date->format('Y-m'));
    }

    public static function lastDay(\DateTimeInterface $date): \DateTimeImmutable
    {
        return self::firstDay($date)->modify('last day of this month');
    }

    /** Number of days in the inclusive period */
    public static function days(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) \DateTimeImmutable::createFromInterface($start)->setTime(0, 0)->diff(\DateTimeImmutable::createFromInterface($end)->setTime(0, 0))->days + 1;
    }

    /** The period starts on the 1st and ends on the last day of a month */
    public static function isWholeMonths(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return '01' === $start->format('d') && $end->format('Y-m-d') === self::lastDay($end)->format('Y-m-d');
    }

    /**
     * "Сентябрь 2026", "Сентябрь 2026 — Ноябрь 2026" for whole months;
     * "11–20 сентября 2026", "28 сентября — 5 октября 2026", "28 декабря 2026 — 5 января 2027" for days.
     */
    #[AsTwigFunction('period_label')]
    public static function periodLabel(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        if (self::isWholeMonths($start, $end)) {
            return $start->format('Y-m') === $end->format('Y-m') ? self::label($start) : self::label($start).' — '.self::label($end);
        }

        $day = static fn (\DateTimeInterface $date, string $pattern) => (string) (new \IntlDateFormatter('ru_RU', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern))->format($date);

        return match (true) {
            $start->format('Y-m-d') === $end->format('Y-m-d') => $day($start, 'd MMMM y'),
            $start->format('Y-m') === $end->format('Y-m') => $start->format('j').'–'.$day($end, 'd MMMM y'),
            $start->format('Y') === $end->format('Y') => $day($start, 'd MMMM').' — '.$day($end, 'd MMMM y'),
            default => $day($start, 'd MMMM y').' — '.$day($end, 'd MMMM y'),
        };
    }

    /**
     * @return list<\DateTimeImmutable> $count months starting with the month of $from
     */
    public static function range(\DateTimeInterface $from, int $count): array
    {
        $first = self::firstDay($from);

        return array_map(static fn (int $i) => $first->modify(\sprintf('+%d months', $i)), range(0, $count - 1));
    }

    /**
     * @return list<\DateTimeImmutable> every month from $start to $end inclusive
     */
    public static function between(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $months = [];
        for ($month = self::firstDay($start); $month <= $end; $month = $month->modify('+1 month')) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * "Сентябрь 2026"; short: "сен 26".
     */
    #[AsTwigFilter('month_label')]
    public static function label(\DateTimeInterface $month, bool $short = false): string
    {
        $formatter = new \IntlDateFormatter('ru_RU', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $short ? 'LLL yy' : 'LLLL y');
        $label = rtrim((string) $formatter->format($month), '.');

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }
}
