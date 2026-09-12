<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One side in a media plan, with the monthly price fixed when it was added (editable by the manager).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_media_plan_side', columns: ['plan_id', 'side_id'])]
class MediaPlanItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MediaPlan $plan = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductSide $side;

    /** Clip length in seconds for airtime (video) sides */
    #[ORM\Column(nullable: true)]
    private ?int $clipDuration;

    /** Selling price per month for this side (and clip), rubles */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $monthlyPrice;

    /** List price per month when added (before promotions); null on items created before promotions existed */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $basePrice = null;

    /** Promotion applied to $monthlyPrice (MediaPlanManager::recalculate) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Promotion $promotion = null;

    /** Kept for the PDF even if the promotion is later deleted */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $promotionTitle = null;

    /** Price typed by a manager: promotions no longer touch it */
    #[ORM\Column(options: ['default' => false])]
    private bool $manualPrice = false;

    /** Booking created from the plan ("Забронировать"), if any */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Booking $booking = null;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(ProductSide $side, string $monthlyPrice, ?int $clipDuration = null)
    {
        $this->side = $side;
        $this->monthlyPrice = $monthlyPrice;
        $this->basePrice = $monthlyPrice;
        $this->clipDuration = $clipDuration;
    }

    public function getBasePrice(): float
    {
        return (float) ($this->basePrice ?? $this->monthlyPrice);
    }

    public function getPromotion(): ?Promotion
    {
        return $this->promotion;
    }

    public function getPromotionTitle(): ?string
    {
        return $this->promotionTitle;
    }

    /** The price is lower than the list price because of a promotion */
    public function hasPromotionPrice(): bool
    {
        return null !== $this->promotionTitle && $this->getMonthlyPrice() < $this->getBasePrice();
    }

    /**
     * Sets the price from a promotion (or back to the list price when $promotion is null).
     */
    public function applyPromotion(?Promotion $promotion, float $price): static
    {
        $this->promotion = $promotion;
        $this->promotionTitle = $promotion?->getTitle();
        $this->monthlyPrice = number_format($price, 2, '.', '');

        return $this;
    }

    public function isManualPrice(): bool
    {
        return $this->manualPrice;
    }

    /**
     * A manager's own price: drops the promotion and stops automatic recalculation.
     */
    public function setManualPrice(string $price): static
    {
        $this->monthlyPrice = $price;
        $this->manualPrice = true;
        $this->promotion = null;
        $this->promotionTitle = null;

        return $this;
    }

    public function resetManualPrice(): static
    {
        $this->manualPrice = false;

        return $this;
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

    public function getSide(): ProductSide
    {
        return $this->side;
    }

    public function getProduct(): ?Product
    {
        return $this->side->getProduct();
    }

    public function getClipDuration(): ?int
    {
        return $this->clipDuration;
    }

    public function getMonthlyPrice(): float
    {
        return (float) $this->monthlyPrice;
    }

    public function setMonthlyPrice(string $monthlyPrice): static
    {
        $this->monthlyPrice = $monthlyPrice;

        return $this;
    }

    /**
     * What Redbox pays the partner per month for this side; 0 for own structures.
     * Partner prices, like selling prices, are per side (per 5 s of the loop for airtime).
     */
    public function getMonthlyPartnerCost(): float
    {
        $product = $this->getProduct();
        if (null === $product || $product->isOwn() || null === $product->getPurchasePrice()) {
            return 0.0;
        }

        return (float) $product->getPurchasePrice() * (null !== $this->clipDuration ? $this->clipDuration / 5 : 1);
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(?Booking $booking): static
    {
        $this->booking = $booking;

        return $this;
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
