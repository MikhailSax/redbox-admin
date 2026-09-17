<?php

namespace App\Dto\Api;

use App\Enum\ClientType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contacts and requisites a client edits in the personal account ("Настройки").
 * Requisites are validated by User::validateRequisites() for the chosen client type.
 */
final class ProfileRequest
{
    #[Assert\NotBlank(message: 'Укажите контактное лицо', normalizer: 'trim')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Укажите телефон', normalizer: 'trim')]
    #[Assert\Length(max: 50)]
    public ?string $phone = null;

    #[Assert\NotNull(message: 'Выберите, кто клиент: физ. лицо, ИП или юр. лицо')]
    public ?ClientType $clientType = null;

    public ?string $company = null;
    public ?string $inn = null;
    public ?string $kpp = null;
    public ?string $ogrn = null;
    public ?string $legalAddress = null;
}
