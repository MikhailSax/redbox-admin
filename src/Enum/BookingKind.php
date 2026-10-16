<?php

namespace App\Enum;

/**
 * What a booking is placed as. Only a commercial one is sold for money ("Продано за"); social advertising and a bonus
 * are free, and a certificate carries its own sum, counted apart from the money. Every kind takes its side all the same.
 */
enum BookingKind: string
{
    case Commercial = 'commercial';
    /** Social advertising, free */
    case Social = 'social';
    /** Placed against a certificate the client holds; the certificate's sum is kept on the booking */
    case Certificate = 'certificate';
    /** Given for free, as a bonus */
    case Bonus = 'bonus';

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Коммерческая',
            self::Social => 'Соцреклама',
            self::Certificate => 'Сертификат',
            self::Bonus => 'Бонус',
        };
    }

    /** Sold for money: has "Продано за", is paid for, counts in revenue and shipments */
    public function isSold(): bool
    {
        return self::Commercial === $this;
    }

    /** Nothing to pay for the placement and no sum of its own */
    public function isFree(): bool
    {
        return self::Social === $this || self::Bonus === $this;
    }
}
