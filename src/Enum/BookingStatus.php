<?php

namespace App\Enum;

/**
 * Where a booking stands. Payment is tracked apart (Booking::isPaid()): post-paying clients
 * get their booking confirmed first and pay later.
 */
enum BookingStatus: string
{
    /** Reserved, waiting for confirmation; released automatically after the hold expires */
    case Hold = 'hold';
    /** The side is the client's: no longer released automatically, whether paid yet or not */
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    /** Hold was not confirmed in time */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Hold => 'Ждёт подтверждения',
            self::Confirmed => 'Подтверждена',
            self::Cancelled => 'Отменена',
            self::Expired => 'Истекла',
        };
    }
}
