<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One side the visitor put in the cart, with the period and the price they were shown.
 * The structure and the side are kept as text too, so an old request still reads well
 * after the structure is renamed or removed.
 */
#[ORM\Entity]
class LeadItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Lead $lead = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ProductSide $side = null;

    #[ORM\Column(length: 255)]
    private string $productTitle;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sideName;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    /** Inclusive */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    /** Seconds, for airtime sides */
    #[ORM\Column(nullable: true)]
    private ?int $clipDuration;

    /** Price per month shown on the website, rubles */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $monthlyPrice;

    public function __construct(
        ?ProductSide $side,
        string $productTitle,
        ?string $sideName,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?int $clipDuration = null,
        ?string $monthlyPrice = null,
    ) {
        $this->side = $side;
        $this->productTitle = mb_substr($productTitle, 0, 255);
        $this->sideName = $sideName;
        $this->startDate = $startDate->setTime(0, 0);
        $this->endDate = $endDate->setTime(0, 0);
        $this->clipDuration = $clipDuration;
        $this->monthlyPrice = $monthlyPrice;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): static
    {
        $this->lead = $lead;

        return $this;
    }

    public function getSide(): ?ProductSide
    {
        return $this->side;
    }

    public function getProduct(): ?Product
    {
        return $this->side?->getProduct();
    }

    public function getProductTitle(): string
    {
        return $this->productTitle;
    }

    public function getSideName(): ?string
    {
        return $this->sideName;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getDays(): int
    {
        return (int) $this->startDate->diff($this->endDate)->days + 1;
    }

    public function getClipDuration(): ?int
    {
        return $this->clipDuration;
    }

    public function getMonthlyPrice(): ?float
    {
        return null !== $this->monthlyPrice ? (float) $this->monthlyPrice : null;
    }

    /** Rough total of the period at the price shown on the website (30 days = a month) */
    public function getTotal(): float
    {
        return null !== $this->monthlyPrice ? round((float) $this->monthlyPrice * $this->getDays() / 30, 2) : 0.0;
    }
}
