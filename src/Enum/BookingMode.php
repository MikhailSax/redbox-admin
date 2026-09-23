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
     * Share of the block taken by $usedSlots, 0..100. Rounded down, so 100% means "not a slot left".
     */
    public static function loadPercent(int $usedSlots, int $slotCount): int
    {
        return $slotCount > 0 ? (int) floor(min($slotCount, max(0, $usedSlots)) * 100 / $slotCount) : 100;
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
