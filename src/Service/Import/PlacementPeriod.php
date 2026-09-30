<?php

namespace App\Service\Import;

use App\Service\MonthCalendar;

/**
 * Placement periods as managers type them in the occupancy spreadsheet:
 *   "01.10.2026-31.10.2026", "07.10.24-07.01.25", "4.09-4.02.25", "24,09,24-  5,10,24", "03.08.026-02.09.2026"
 *   "сентябрь" — the whole month (of the sheet's year unless given);
 *   "до 23.10.2026", "до конца года" — from the first day of the sheet's month.
 * A year left out is taken from the other date (a period doesn't run backwards), otherwise from the sheet.
 */
final class PlacementPeriod
{
    private const MONTHS = [
        'январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4, 'май' => 5, 'мая' => 5, 'июн' => 6,
        'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12,
    ];

    /**
     * @param \DateTimeImmutable $sheetMonth first day of the month the sheet is about
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null [first day, last day]; null when not understood
     */
    public static function parse(string $text, \DateTimeImmutable $sheetMonth): ?array
    {
        $text = mb_strtolower(trim($text));
        $sheetYear = (int) $sheetMonth->format('Y');

        preg_match_all('/(\d{1,2})[.,](\d{1,2})(?:[.,]+(\d{2,4}))?/u', $text, $dates, \PREG_SET_ORDER);

        if (2 === \count($dates)) {
            [$from, $to] = $dates;
            $endYear = self::year($to[3] ?? null) ?? self::year($from[3] ?? null) ?? $sheetYear;
            $startYear = self::year($from[3] ?? null) ?? $endYear;
            if (null === self::year($from[3] ?? null) && [(int) $from[2], (int) $from[1]] > [(int) $to[2], (int) $to[1]]) {
                --$startYear; // "15.12-14.01.27"
            }
            $start = self::date($startYear, (int) $from[2], (int) $from[1]);
            $end = self::date($endYear, (int) $to[2], (int) $to[1]);
        } elseif (1 === \count($dates) && str_starts_with($text, 'до')) {
            $start = $sheetMonth;
            $end = self::date(self::year($dates[0][3] ?? null) ?? $sheetYear, (int) $dates[0][2], (int) $dates[0][1]);
        } elseif ([] === $dates && str_starts_with($text, 'до конца года')) {
            $start = $sheetMonth;
            $end = self::date($sheetYear, 12, 31);
        } elseif (null !== ($month = self::month($text))) {
            $start = self::date($month[1] ?? $sheetYear, $month[0], 1);
            $end = MonthCalendar::lastDay($start);
        } else {
            return null;
        }

        return null !== $start && null !== $end && $start <= $end ? [$start, $end] : null;
    }

    /**
     * "октябрь 2026" => [10, 2026], "сентябрь" => [9, null].
     *
     * @return array{0: int, 1: ?int}|null [month, year when written]
     */
    public static function month(string $text): ?array
    {
        if (!preg_match('/^([а-яё]+)\s*(\d{4})?$/u', mb_strtolower(trim($text)), $m)) {
            return null;
        }
        foreach (self::MONTHS as $stem => $number) {
            if (str_starts_with($m[1], $stem)) {
                return [$number, self::year($m[2] ?? null)];
            }
        }

        return null;
    }

    /** "26", "026", "2026" => 2026 */
    private static function year(?string $digits): ?int
    {
        if (null === $digits || '' === $digits) {
            return null;
        }
        $year = (int) $digits;

        return $year < 1000 ? 2000 + $year % 100 : $year;
    }

    private static function date(int $year, int $month, int $day): ?\DateTimeImmutable
    {
        return checkdate($month, $day, $year) ? new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $year, $month, $day)) : null;
    }
}
