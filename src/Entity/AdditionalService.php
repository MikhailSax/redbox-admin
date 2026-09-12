<?php

namespace App\Entity;

use App\Repository\AdditionalServiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paid extra that comes with placement: layout design, banner printing, mounting…
 * Added to media plans as service lines (the price is copied, so later price changes don't touch old plans).
 */
#[ORM\Entity(repositoryClass: AdditionalServiceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('name', message: 'Услуга с таким названием уже есть')]
class AdditionalService
{
    use TimestampableTrait;

    /** Suggestions for the unit field; any short unit can be typed */
    public const UNITS = ['шт', 'м²', 'п.м.', 'макет', 'комплект', 'услуга', 'час', 'день', 'месяц', 'выезд'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите название', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Укажите единицу', normalizer: 'trim')]
    #[Assert\Length(max: 20)]
    private string $unit = 'шт';

    /** Selling price per unit, rubles */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Укажите цену')]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $price = null;

    /** What the service costs Redbox per unit (printing house, installers); optional, for the margin */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Себестоимость не может быть отрицательной')]
    private ?string $costPrice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Inactive services stay in old plans but can't be picked for new lines */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getCostPrice(): ?string
    {
        return $this->costPrice;
    }

    public function setCostPrice(?string $costPrice): static
    {
        $this->costPrice = $costPrice;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
