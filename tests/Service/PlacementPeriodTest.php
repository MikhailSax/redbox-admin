<?php

namespace App\Tests\Service;

use App\Service\Import\PlacementPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlacementPeriodTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string}>
     */
    public static function periods(): iterable
    {
        yield 'full dates' => ['01.10.2026-31.10.2026', '2026-10-01', '2026-10-31'];
        yield 'space after the dash' => ['01.09.2026- 25.11.2026', '2026-09-01', '2026-11-25'];
        yield 'short years' => ['07.10.24-07.01.25', '2024-10-07', '2025-01-07'];
        yield 'year typo' => ['03.08.026-02.09.2026', '2026-08-03', '2026-09-02'];
        yield 'commas' => ['24,09,24-  5,10,24', '2024-09-24', '2024-10-05'];
        yield 'first year from the second date' => ['4.09-4.02.25', '2024-09-04', '2025-02-04'];
        yield 'no years' => ['22,06-15,09', '2026-06-22', '2026-09-15'];
        yield 'month of the sheet year' => ['сентябрь', '2026-09-01', '2026-09-30'];
        yield 'month with a year' => ['Январь 2027', '2027-01-01', '2027-01-31'];
        yield 'until a day' => ['до 23.10.2026', '2026-10-01', '2026-10-23'];
        yield 'until the end of the year' => ['до конца года', '2026-10-01', '2026-12-31'];
        yield 'one date' => ['15.07.2026', null, null];
        yield 'backwards' => ['31.10.2026-01.10.2026', null, null];
        yield 'no such day' => ['01.10.2026-32.10.2026', null, null];
        yield 'text' => ['пока есть место', null, null];
    }

    #[DataProvider('periods')]
    public function testReadsPeriodsAsTyped(string $text, ?string $start, ?string $end): void
    {
        $period = PlacementPeriod::parse($text, new \DateTimeImmutable('2026-10-01'));

        self::assertSame(null !== $start ? [$start, $end] : null, null !== $period ? [$period[0]->format('Y-m-d'), $period[1]->format('Y-m-d')] : null);
    }

    public function testReadsTheMonthOfASheetName(): void
    {
        self::assertSame([10, 2026], PlacementPeriod::month('октябрь 2026'));
        self::assertSame([5, null], PlacementPeriod::month('Мая'));
        self::assertNull(PlacementPeriod::month('ноябрь (копия)'));
    }
}
