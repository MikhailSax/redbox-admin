<?php

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A side chosen for one position of a package ("slots[].id" and "slots[].options[].sideId" of /api/v1/packages).
 */
final class PackageOrderItemRequest
{
    #[Assert\NotNull(message: 'Укажите позицию пакета')]
    #[Assert\Positive]
    public ?int $slotId = null;

    #[Assert\NotNull(message: 'Укажите сторону конструкции')]
    #[Assert\Positive]
    public ?int $sideId = null;
}
