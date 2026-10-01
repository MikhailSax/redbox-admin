<?php

namespace App\Enum;

/**
 * Sales status of a side / structure for a month, derived from its active bookings.
 */
enum AvailabilityStatus: string
{
    /** Can still be sold: no booking, or airtime left in the loop */
    case Free = 'free';
    /** Nothing left to sell, but at least part of it is an unconfirmed 24h hold */
    case Booked = 'booked';
    /** Nothing left to sell, everything is confirmed (paid or not) */
    case Occupied = 'occupied';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Свободна',
            self::Booked => 'Забронирована',
            self::Occupied => 'Занята',
        };
    }
}
