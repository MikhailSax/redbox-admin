<?php

namespace App\Enum;

/**
 * Where a request from the website stands in the sales funnel.
 */
enum LeadStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Quoted = 'quoted';
    case Booked = 'booked';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::InProgress => 'В работе',
            self::Quoted => 'КП отправлено',
            self::Booked => 'Забронировано',
            self::Paid => 'Оплачено',
            self::Rejected => 'Отказ',
        };
    }

    /** Colour of the badge: neutral, warning, success or danger */
    public function tone(): string
    {
        return match ($this) {
            self::New => 'accent',
            self::InProgress, self::Quoted => 'warning',
            self::Booked, self::Paid => 'success',
            self::Rejected => 'danger',
        };
    }

    /** The request is done with: no more work expected */
    public function isClosed(): bool
    {
        return self::Paid === $this || self::Rejected === $this;
    }
}
