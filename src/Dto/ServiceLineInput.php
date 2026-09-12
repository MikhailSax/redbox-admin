<?php

namespace App\Dto;

use App\Entity\AdditionalService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Add a service" form on the media plan page; becomes a MediaPlanServiceLine.
 */
final class ServiceLineInput
{
    /** Catalog entry; its name, unit and price prefill the line (null = custom service) */
    public ?AdditionalService $service = null;

    #[Assert\NotBlank(message: 'Выберите услугу или впишите название', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'Укажите единицу', normalizer: 'trim')]
    #[Assert\Length(max: 20)]
    public ?string $unit = 'шт';

    #[Assert\NotBlank(message: 'Укажите количество')]
    #[Assert\Positive(message: 'Количество должно быть больше нуля')]
    public ?string $quantity = '1';

    #[Assert\NotBlank(message: 'Укажите цену')]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    public ?string $unitPrice = null;
}
