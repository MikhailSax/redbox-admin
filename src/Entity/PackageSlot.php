<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One position of a package: $quantity sides out of $sides, each at $price a month
 * (null = the side's own list price, 0 = a gift).
 * A screen is taken by one slot of its block, like everything sold on the website.
 */
#[ORM\Entity]
class PackageSlot
{
    public const MAX_QUANTITY = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'slots')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Package $package = null;

    /** "Суперсайт 12×4", "Экран 6×3 на выбор", "Экран в подарок" */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Назовите позицию', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Сколько сторон?')]
    #[Assert\Range(notInRangeMessage: 'От {{ min }} до {{ max }}', min: 1, max: self::MAX_QUANTITY)]
    private ?int $quantity = 1;

    /** Per side per month, rubles; null = list price, 0 = gift */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть меньше нуля')]
    private ?string $price = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /**
     * Sides the client chooses from
     *
     * @var Collection<int, ProductSide>
     */
    #[ORM\ManyToMany(targetEntity: ProductSide::class)]
    #[ORM\JoinTable(name: 'package_slot_side')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $sides;

    public function __construct()
    {
        $this->sides = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (null !== $this->quantity && $this->sides->count() < $this->quantity) {
            $context->buildViolation(\sprintf('Выберите не меньше %d %s — столько клиент берёт в этой позиции', $this->quantity, 1 === $this->quantity ? 'стороны' : 'сторон'))
                ->atPath('sides')
                ->addViolation();
        }
    }

    public function isGift(): bool
    {
        return null !== $this->price && 0.0 === (float) $this->price;
    }

    /** Takes the side's own price */
    public function isListPrice(): bool
    {
        return null === $this->price;
    }

    /**
     * Price of a side per month in this slot: the package price, or the side's list price for $months
     * (for a screen, one slot of its block).
     */
    public function priceFor(ProductSide $side, int $months): float
    {
        return null !== $this->price ? (float) $this->price : (float) ($side->getMonthlyPriceFor($months) ?? 0);
    }

    /** "14 800 ₽", "в подарок", "по прайсу" */
    public function getPriceLabel(): string
    {
        return match (true) {
            $this->isGift() => 'в подарок',
            null === $this->price => 'по прайсу',
            default => number_format((float) $this->price, 0, ',', ' ').' ₽',
        };
    }

    public function hasSide(ProductSide $side): bool
    {
        return $this->sides->contains($side);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPackage(): ?Package
    {
        return $this->package;
    }

    public function setPackage(?Package $package): static
    {
        $this->package = $package;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): static
    {
        $this->quantity = $quantity;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return Collection<int, ProductSide>
     */
    public function getSides(): Collection
    {
        return $this->sides;
    }

    public function addSide(ProductSide $side): static
    {
        if (!$this->sides->contains($side)) {
            $this->sides->add($side);
        }

        return $this;
    }

    public function removeSide(ProductSide $side): static
    {
        $this->sides->removeElement($side);

        return $this;
    }
}
