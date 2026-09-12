<?php

namespace App\Enum;

enum PromotionDiscountType: string
{
    /** Percent off the monthly price */
    case Percent = 'percent';

    /** Rubles off the monthly price of each side */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Процент',
            self::Fixed => 'Сумма, ₽',
        };
    }
}
