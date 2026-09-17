<?php

namespace App\Enum;

/**
 * Where a scheduled payment stands today; derived from its due date and payment date (never stored).
 */
enum PaymentStatus: string
{
    /** Due later than in SOON_DAYS */
    case Upcoming = 'upcoming';

    /** Due today or within SOON_DAYS */
    case DueSoon = 'soon';

    /** The due date has passed and it isn't paid */
    case Overdue = 'overdue';

    case Paid = 'paid';

    /** "Soon" means the due date is at most this many days ahead */
    public const SOON_DAYS = 3;

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Ожидается',
            self::DueSoon => 'Скоро срок',
            self::Overdue => 'Просрочен',
            self::Paid => 'Оплачен',
        };
    }
}
