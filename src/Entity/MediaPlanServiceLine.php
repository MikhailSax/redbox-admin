<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A one-off paid service in a media plan (e.g. "Печать баннера, 18 м²").
 * Name, unit and prices are copied from the catalog, so the plan keeps its numbers if the catalog changes.
 */
#[ORM\Entity]
class MediaPlanServiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'serviceLines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MediaPlan $plan = null;

    /** Catalog entry it came from; null for a custom line or a deleted catalog entry */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AdditionalService $service;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $unit;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    /** Redbox's own cost per unit, for the margin; null = unknown (counted as 0) */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $unitCost;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(string $name, string $unit, string $quantity, string $unitPrice, ?string $unitCost = null, ?AdditionalService $service = null)
    {
        $this->name = $name;
        $this->unit = $unit;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
        $this->unitCost = $unitCost;
        $this->service = $service;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): ?MediaPlan
    {
        return $this->plan;
    }

    public function setPlan(?MediaPlan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getService(): ?AdditionalService
    {
        return $this->service;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function getQuantity(): float
    {
        return (float) $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPrice(): float
    {
        return (float) $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getUnitCost(): ?float
    {
        return null !== $this->unitCost ? (float) $this->unitCost : null;
    }

    public function getTotal(): float
    {
        return round($this->getQuantity() * $this->getUnitPrice(), 2);
    }

    public function getCost(): float
    {
        return round($this->getQuantity() * ($this->getUnitCost() ?? 0), 2);
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
