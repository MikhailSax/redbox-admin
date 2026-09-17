<?php

namespace App\Enum;

/**
 * How a structure type is sold; set on ProductType.
 */
enum BookingMode: string
{
    /** A whole side is booked per calendar month (static, prismatron, …) */
    case Side = 'side';

    /** Airtime: clips share a loop on the screen side (video screens) */
    case Airtime = 'airtime';

    /** Length of the video loop, seconds */
    public const LOOP_SECONDS = 120;

    /** Allowed clip lengths, seconds */
    public const CLIP_DURATIONS = [5, 10, 15];

    /**
     * Share of the loop taken by $usedSeconds, 0..100. Rounded down, so 100% means "not a second left".
     */
    public static function loadPercent(int $usedSeconds): int
    {
        return (int) floor(min(self::LOOP_SECONDS, max(0, $usedSeconds)) * 100 / self::LOOP_SECONDS);
    }

    public function isAirtime(): bool
    {
        return self::Airtime === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Side => 'Сторона на месяц',
            self::Airtime => 'Эфир: ролики в петле 2 мин',
        };
    }
}
