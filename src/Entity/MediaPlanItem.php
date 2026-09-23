<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One side in a media plan, with the monthly price fixed when it was added (editable by the manager).
 * The name, format and description printed in the PDF come from the structure unless the manager typed
 * this proposal's own ones.
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

    /** Slots of the screen's block, for airtime (video) sides */
    #[ORM\Column(nullable: true)]
    private ?int $slots;

    /** Selling price per month for this side (all its slots), rubles */
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

    /** The structure's name in this proposal; null = Product::$name */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    /** "Формат" line in the PDF; null = category and the side's type */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $format = null;

    /** Description in the PDF; null = Product::$shortDescription */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function __construct(ProductSide $side, string $monthlyPrice, ?int $slots = null)
    {
        $this->side = $side;
        $this->monthlyPrice = $monthlyPrice;
        $this->basePrice = $monthlyPrice;
        $this->slots = $slots;
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

    public function getSlots(): ?int
    {
        return $this->slots;
    }

    public function setSlots(?int $slots): static
    {
        $this->slots = $slots;

        return $this;
    }

    /** List price per month set anew (the price list, the slots or the term changed); promotions are applied on top */
    public function setBasePrice(string $basePrice): static
    {
        $this->basePrice = $basePrice;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * The proposal's own texts; an empty one, or one equal to the structure's, falls back to the structure's.
     */
    public function setTexts(?string $title, ?string $format, ?string $description): static
    {
        $own = static fn (?string $value, ?string $default): ?string => '' === ($value = trim((string) $value)) || $value === trim((string) $default) ? null : $value;
        $this->title = $own($title, $this->getProduct()?->getName());
        $this->format = $own($format, $this->getDefaultFormat());
        $this->description = $own($description, $this->getProduct()?->getShortDescription());

        return $this;
    }

    public function hasOwnTexts(): bool
    {
        return null !== $this->title || null !== $this->format || null !== $this->description;
    }

    public function getDisplayTitle(): string
    {
        return $this->title ?? (string) $this->getProduct()?->getName();
    }

    public function getDisplayFormat(): string
    {
        return $this->format ?? $this->getDefaultFormat();
    }

    public function getDisplayDescription(): ?string
    {
        return $this->description ?? $this->getProduct()?->getShortDescription();
    }

    /** "Суперсайт, видеоэкран · 12 × 4 м": the side's own type, not the structure's (sides may differ) */
    public function getDefaultFormat(): string
    {
        $product = $this->getProduct();
        $type = $this->side->getEffectiveProductType()?->getName();

        return implode(' · ', array_filter([
            implode(', ', array_filter([$product?->getCategory()?->getName(), null !== $type ? mb_strtolower($type) : null])),
            $product?->getSizeLabel(),
        ]));
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
     * Partner prices, like selling prices, are per side (per slot for airtime).
     */
    public function getMonthlyPartnerCost(): float
    {
        $product = $this->getProduct();
        if (null === $product || $product->isOwn() || null === $product->getPurchasePrice()) {
            return 0.0;
        }

        return (float) $product->getPurchasePrice() * ($this->slots ?? 1);
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
