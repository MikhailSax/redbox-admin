<?php

namespace App\Entity;

use App\Helpers\ProductHelper;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An advertising structure (billboard, screen, ...).
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Product
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите название', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    /** Number in the company's address programme ("858", "7/23"); several structures at one address may share it */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $schemeNumber = null;

    /** Key of ProductHelper::SIZES */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(callback: [ProductHelper::class, 'sizeKeys'], message: 'Выберите размер из списка')]
    private ?string $size = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $seoTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $seoDescription = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $seoKeywords = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Выберите категорию')]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Выберите тип конструкции')]
    private ?ProductType $productType = null;

    /**
     * Required in the form; nullable in the DB because deleting a district clears it (ON DELETE SET NULL).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'Выберите район')]
    private ?District $district = null;

    /**
     * Monthly rent in rubles (Redbox's selling price, per side; for airtime — per 5 s of the loop).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\NotBlank(message: 'Укажите цену')]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $price = null;

    /**
     * Partner who owns the structure; null = Redbox's own structure.
     * Deleting a partner that still has structures is refused (RESTRICT).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Partner $owner = null;

    /**
     * What Redbox pays the partner per month, same unit as $price. Partner structures only.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $purchasePrice = null;

    /**
     * Latitude in decimal degrees (WGS 84), e.g. 55.7539303.
     * Nullable in the DB only for rows created before coordinates were introduced.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    #[Assert\NotBlank(message: 'Укажите широту')]
    #[Assert\Range(notInRangeMessage: 'Широта должна быть от {{ min }} до {{ max }}', min: -90, max: 90)]
    private ?string $latitude = null;

    /**
     * Longitude in decimal degrees (WGS 84), e.g. 37.6205606.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    #[Assert\NotBlank(message: 'Укажите долготу')]
    #[Assert\Range(notInRangeMessage: 'Долгота должна быть от {{ min }} до {{ max }}', min: -180, max: 180)]
    private ?string $longitude = null;

    /**
     * @var Collection<int, ProductSide>
     */
    #[ORM\OneToMany(targetEntity: ProductSide::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    #[Assert\Count(min: 1, minMessage: 'Добавьте хотя бы одну сторону')]
    #[Assert\Valid]
    private Collection $sides;

    public function __construct()
    {
        $this->sides = new ArrayCollection();
    }

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

    public function getSchemeNumber(): ?string
    {
        return $this->schemeNumber;
    }

    public function setSchemeNumber(?string $schemeNumber): static
    {
        $schemeNumber = null !== $schemeNumber ? trim($schemeNumber) : null;
        $this->schemeNumber = '' !== $schemeNumber ? $schemeNumber : null;

        return $this;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getSizeLabel(): ?string
    {
        return ProductHelper::sizeLabel($this->size);
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;

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

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = $seoTitle;

        return $this;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = $seoDescription;

        return $this;
    }

    public function getSeoKeywords(): ?string
    {
        return $this->seoKeywords;
    }

    public function setSeoKeywords(?string $seoKeywords): static
    {
        $this->seoKeywords = $seoKeywords;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getProductType(): ?ProductType
    {
        return $this->productType;
    }

    public function setProductType(?ProductType $productType): static
    {
        $this->productType = $productType;

        return $this;
    }

    public function getDistrict(): ?District
    {
        return $this->district;
    }

    public function setDistrict(?District $district): static
    {
        $this->district = $district;

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

    public function getOwner(): ?Partner
    {
        return $this->owner;
    }

    public function setOwner(?Partner $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function isOwn(): bool
    {
        return null === $this->owner;
    }

    public function getPurchasePrice(): ?string
    {
        return $this->purchasePrice;
    }

    public function setPurchasePrice(?string $purchasePrice): static
    {
        $this->purchasePrice = $purchasePrice;

        return $this;
    }

    /**
     * Monthly margin of a partner structure (selling price minus the partner's price); null when unknown or own.
     */
    public function getMargin(): ?float
    {
        if ($this->isOwn() || null === $this->price || null === $this->purchasePrice) {
            return null;
        }

        return (float) $this->price - (float) $this->purchasePrice;
    }

    /**
     * Margin as a share of the selling price, 0..100.
     */
    public function getMarginPercent(): ?float
    {
        $margin = $this->getMargin();

        return null !== $margin && (float) $this->price > 0 ? $margin / (float) $this->price * 100 : null;
    }

    #[Assert\Callback]
    public function validatePartnerPricing(ExecutionContextInterface $context): void
    {
        if (null !== $this->owner && null === $this->purchasePrice) {
            $context->buildViolation('Укажите цену партнёра')->atPath('purchasePrice')->addViolation();
        }
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;

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
            $side->setProduct($this);
        }

        return $this;
    }

    public function removeSide(ProductSide $side): static
    {
        $this->sides->removeElement($side);

        return $this;
    }

    /**
     * First photo of the first side that has one; used as the list thumbnail.
     */
    public function getCoverPhoto(): ?ProductSidePhoto
    {
        foreach ($this->sides as $side) {
            $photo = $side->getPhotos()->first();
            if ($photo instanceof ProductSidePhoto) {
                return $photo;
            }
        }

        return null;
    }
}
