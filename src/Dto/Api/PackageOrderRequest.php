<?php

namespace App\Dto\Api;

use App\Entity\Package;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The package offer in an order: the package, the period and the sides chosen for each of its positions.
 */
final class PackageOrderRequest
{
    #[Assert\NotNull(message: 'Укажите пакет')]
    #[Assert\Positive]
    public ?int $id = null;

    /** First day, "YYYY-MM-DD" */
    #[Assert\NotBlank(message: 'Укажите начало размещения')]
    #[Assert\Date(message: 'Дата в формате ГГГГ-ММ-ДД')]
    public ?string $from = null;

    #[Assert\NotNull(message: 'Укажите срок размещения')]
    #[Assert\Range(notInRangeMessage: 'Срок — от {{ min }} до {{ max }} месяцев', min: 1, max: Package::MAX_MONTHS)]
    public ?int $months = null;

    /**
     * @var list<PackageOrderItemRequest>
     */
    #[Assert\Valid]
    #[Assert\Count(min: 1, max: 50, minMessage: 'Выберите конструкции пакета', maxMessage: 'Не больше {{ limit }} конструкций в пакете')]
    public array $items = [];

    /**
     * @return array<int, list<int>> position id => side ids
     */
    public function selection(): array
    {
        $selection = [];
        foreach ($this->items as $item) {
            $selection[(int) $item->slotId][] = (int) $item->sideId;
        }

        return $selection;
    }
}
