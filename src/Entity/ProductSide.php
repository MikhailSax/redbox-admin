<?php

namespace App\Entity;

use App\Enum\BookingMode;
use App\Repository\ProductSideRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A side of an advertising structure (A, B, ...).
 */
#[ORM\Entity(repositoryClass: ProductSideRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProductSide
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Укажите название стороны', normalizer: 'trim')]
    #[Assert\Length(max: 50)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Monthly price of this side when it differs from the structure's price (sides facing traffic cost more); null = Product::$price.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $price = null;

    /**
     * Type of this side when it differs from the structure's (a video screen on one side, a static poster on the other);
     * null = Product::$productType. The type decides how the side is booked.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ProductType $productType = null;

    #[ORM\ManyToOne(inversedBy: 'sides')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    /**
     * @var Collection<int, ProductSidePhoto>
     */
    #[ORM\OneToMany(targetEntity: ProductSidePhoto::class, mappedBy: 'side', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->photos = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    /** This side's own price, otherwise the structure's */
    public function getEffectivePrice(): ?string
    {
        return $this->price ?? $this->product?->getPrice();
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

    /** This side's own type, otherwise the structure's */
    public function getEffectiveProductType(): ?ProductType
    {
        return $this->productType ?? $this->product?->getProductType();
    }

    public function getBookingMode(): BookingMode
    {
        return $this->getEffectiveProductType()?->getBookingMode() ?? BookingMode::Side;
    }

    /** Sold as airtime (clips in the loop) rather than as a whole side per month */
    public function isAirtime(): bool
    {
        return $this->getBookingMode()->isAirtime();
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    /**
     * @return Collection<int, ProductSidePhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(ProductSidePhoto $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $photo->setPosition(\count($this->photos));
            $this->photos->add($photo);
            $photo->setSide($this);
        }

        return $this;
    }

    public function removePhoto(ProductSidePhoto $photo): static
    {
        $this->photos->removeElement($photo);

        return $this;
    }
}
