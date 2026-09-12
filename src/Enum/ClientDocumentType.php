<?php

namespace App\Enum;

enum ClientDocumentType: string
{
    case Contract = 'contract';
    case Appendix = 'appendix';
    case Invoice = 'invoice';
    case Act = 'act';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Договор',
            self::Appendix => 'Приложение к договору',
            self::Invoice => 'Счёт',
            self::Act => 'Акт',
            self::Other => 'Другое',
        };
    }
}
