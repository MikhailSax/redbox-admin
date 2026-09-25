<?php

namespace App\Enum;

/**
 * Who the client is legally: decides which requisites the card asks for.
 */
enum ClientType: string
{
    /** A private person: name, phone, email */
    case Individual = 'individual';

    /** Individual entrepreneur: ИНН (12 digits), ОГРНИП */
    case Entrepreneur = 'entrepreneur';

    /** Company: ИНН (10 digits), КПП, ОГРН, legal address */
    case Legal = 'legal';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Физ. лицо',
            self::Entrepreneur => 'ИП',
            self::Legal => 'Юр. лицо',
        };
    }

    /** ИП and companies have requisites; a private person doesn't */
    public function hasRequisites(): bool
    {
        return self::Individual !== $this;
    }

    /** Digits in ИНН */
    public function innLength(): ?int
    {
        return match ($this) {
            self::Individual => null,
            self::Entrepreneur => 12,
            self::Legal => 10,
        };
    }

    /** By the ИНН: 10 digits — a company, 12 — an entrepreneur, none — a private person */
    public static function fromInn(?string $inn): self
    {
        return match (\strlen((string) preg_replace('/\D+/', '', (string) $inn))) {
            10 => self::Legal,
            12 => self::Entrepreneur,
            default => self::Individual,
        };
    }

    /** Digits in ОГРН (company) or ОГРНИП (entrepreneur) */
    public function ogrnLength(): ?int
    {
        return match ($this) {
            self::Individual => null,
            self::Entrepreneur => 15,
            self::Legal => 13,
        };
    }
}
