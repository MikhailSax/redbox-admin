<?php

namespace App\Entity;

use App\Enum\PromotionDiscountType;
use App\Repository\PromotionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Discount on the monthly price of structures.
 *
 * Applies to all structures, or to chosen categories and/or structures, within its dates, and optionally only
 * with a promo code, only for a client's first order, or from N months of placement.
 * The best matching promotion is applied per media plan item (they don't stack), see PromotionResolver.
 */
#[ORM\Entity(repositoryClass: PromotionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('code', message: 'Такой промокод уже есть', ignoreNull: true)]
class Promotion
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите название', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /** Shown to the client in the PDF */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 10, enumType: PromotionDiscountType::class)]
    private PromotionDiscountType $discountType = PromotionDiscountType::Percent;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Укажите размер скидки')]
    #[Assert\Positive(message: 'Скидка должна быть больше нуля')]
    private ?string $discountValue = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Укажите дату начала')]
    private ?\DateTimeImmutable $startsAt = null;

    /** Last day (inclusive); null = no end date */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'startsAt', message: 'Акция не может закончиться раньше, чем начнётся')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Every structure; otherwise only $categories and $products */
    #[ORM\Column(options: ['default' => false])]
    private bool $appliesToAll = false;

    /**
     * @var Collection<int, Category>
     */
    #[ORM\ManyToMany(targetEntity: Category::class)]
    #[ORM\JoinTable(name: 'promotion_category')]
    private Collection $categories;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\ManyToMany(targetEntity: Product::class)]
    #[ORM\JoinTable(name: 'promotion_product')]
    private Collection $products;

    /** Applies only when the media plan has this code; stored upper-case */
    #[ORM\Column(length: 50, nullable: true, unique: true)]
    #[Assert\Length(max: 50)]
    #[Assert\Regex('/^[A-Z0-9_-]+$/u', message: 'Промокод — латинские буквы, цифры, «-» и «_»')]
    private ?string $code = null;

    /** Applies only to a client's first order */
    #[ORM\Column(options: ['default' => false])]
    private bool $firstOrderOnly = false;

    /** Applies from this many months of placement */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'От {{ min }} до {{ max }} месяцев', min: 2, max: 12)]
    private ?int $minMonths = null;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->products = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->appliesToAll && $this->categories->isEmpty() && $this->products->isEmpty()) {
            $context->buildViolation('Выберите категории или конструкции — или отметьте «Все конструкции»')->atPath('appliesToAll')->addViolation();
        }
        if (PromotionDiscountType::Percent === $this->discountType && null !== $this->discountValue && (float) $this->discountValue > 90) {
            $context->buildViolation('Скидка — не больше 90%')->atPath('discountValue')->addViolation();
        }
    }

    /**
     * Whether the promotion is switched on and $date is within its dates.
     */
    public function isRunningOn(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->active
            && null !== $this->startsAt && $this->startsAt->format('Y-m-d') <= $day
            && (null === $this->endsAt || $this->endsAt->format('Y-m-d') >= $day);
    }

    /**
     * "active" | "scheduled" | "finished" | "disabled"
     */
    public function getStateOn(\DateTimeInterface $date): string
    {
        $day = $date->format('Y-m-d');

        return match (true) {
            !$this->active => 'disabled',
            $this->startsAt?->format('Y-m-d') > $day => 'scheduled',
            null !== $this->endsAt && $this->endsAt->format('Y-m-d') < $day => 'finished',
            default => 'active',
        };
    }

    public function targets(Product $product): bool
    {
        return $this->appliesToAll
            || $this->products->contains($product)
            || (null !== $product->getCategory() && $this->categories->contains($product->getCategory()));
    }

    /** No promo code and no first-order condition: visible to every client (badges in the list) */
    public function isPublic(): bool
    {
        return null === $this->code && !$this->firstOrderOnly;
    }

    public function apply(float $price): float
    {
        $discounted = PromotionDiscountType::Percent === $this->discountType
            ? $price * (1 - (float) $this->discountValue / 100)
            : $price - (float) $this->discountValue;

        return max(0.0, round($discounted, 2));
    }

    /** "−20%" / "−5 000 ₽" */
    public function getDiscountLabel(): string
    {
        return PromotionDiscountType::Percent === $this->discountType
            ? '−'.rtrim(rtrim(number_format((float) $this->discountValue, 2, ',', ''), '0'), ',').'%'
            : '−'.number_format((float) $this->discountValue, 0, ',', ' ').' ₽';
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDiscountType(): PromotionDiscountType
    {
        return $this->discountType;
    }

    public function setDiscountType(PromotionDiscountType $discountType): static
    {
        $this->discountType = $discountType;

        return $this;
    }

    public function getDiscountValue(): ?string
    {
        return $this->discountValue;
    }

    public function setDiscountValue(?string $discountValue): static
    {
        $this->discountValue = $discountValue;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

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

    public function isAppliesToAll(): bool
    {
        return $this->appliesToAll;
    }

    public function setAppliesToAll(bool $appliesToAll): static
    {
        $this->appliesToAll = $appliesToAll;

        return $this;
    }

    /**
     * @return Collection<int, Category>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(Category $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(Category $category): static
    {
        $this->categories->removeElement($category);

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        $this->products->removeElement($product);

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $code = null !== $code ? mb_strtoupper(trim($code)) : null;
        $this->code = '' !== $code ? $code : null;

        return $this;
    }

    public function isFirstOrderOnly(): bool
    {
        return $this->firstOrderOnly;
    }

    public function setFirstOrderOnly(bool $firstOrderOnly): static
    {
        $this->firstOrderOnly = $firstOrderOnly;

        return $this;
    }

    public function getMinMonths(): ?int
    {
        return $this->minMonths;
    }

    public function setMinMonths(?int $minMonths): static
    {
        $this->minMonths = $minMonths;

        return $this;
    }
}
