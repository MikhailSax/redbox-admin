<?php

namespace App\Enum;

enum BookingStatus: string
{
    /** Reserved, waiting for payment; released automatically after the hold expires */
    case Hold = 'hold';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    /** Hold was not paid in time */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Hold => 'Ждёт оплаты',
            self::Paid => 'Оплачена',
            self::Cancelled => 'Отменена',
            self::Expired => 'Истекла',
        };
    }
}
