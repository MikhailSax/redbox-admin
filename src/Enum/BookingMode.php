<?php

namespace App\Enum;

/**
 * How a structure type is sold; set on ProductType.
 */
enum BookingMode: string
{
    /** A whole side is booked per calendar month (static, prismatron, …) */
    case Side = 'side';

    /** Airtime: the screen's block is split into slots, a client takes one or more of them (video screens) */
    case Airtime = 'airtime';

    /** Allowed slot lengths, seconds */
    public const SLOT_DURATIONS = [5, 10];

    public const DEFAULT_SLOT_SECONDS = 5;

    /** Slots in the block of a screen unless set otherwise: 12 × 5 s = a 60 s block */
    public const DEFAULT_SLOT_COUNT = 12;

    public const MAX_SLOT_COUNT = 60;

    /** Shortest placement, days: two weeks */
    public const MIN_DAYS = 14;

    /**
     * Share of the block taken by $used, 0..100 (slots or seconds, the same unit as $capacity).
     * Rounded down, so 100% means "nothing left".
     */
    public static function loadPercent(int $used, int $capacity): int
    {
        return $capacity > 0 ? (int) floor(min($capacity, max(0, $used)) * 100 / $capacity) : 100;
    }

    /**
     * Parts of a $slotSeconds slot a client may buy: the slot itself and every shorter slot length it splits into
     * (10 s → 10 or 5 s; 5 s → 5 s).
     *
     * @return list<int> longest first
     */
    public static function secondsChoices(int $slotSeconds): array
    {
        $choices = array_values(array_filter(self::SLOT_DURATIONS, static fn (int $s) => $s <= $slotSeconds && 0 === $slotSeconds % $s));
        rsort($choices);

        return [] !== $choices ? $choices : [$slotSeconds];
    }

    /** "7", "7,5": slots taken, half slots included */
    public static function slotsLabel(int $seconds, int $slotSeconds): string
    {
        $slots = $seconds / max(1, $slotSeconds);

        return rtrim(rtrim(number_format($slots, 1, ',', ''), '0'), ',');
    }

    public function isAirtime(): bool
    {
        return self::Airtime === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Side => 'Сторона на месяц',
            self::Airtime => 'Эфир: слоты в блоке',
        };
    }
}
