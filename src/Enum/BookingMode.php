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

    public function label(): string
    {
        return match ($this) {
            self::Side => 'Сторона на месяц',
            self::Airtime => 'Эфир: ролики в петле 2 мин',
        };
    }
}
