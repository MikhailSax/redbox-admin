<?php

namespace App\Entity;

use App\Enum\BookingMode;
use App\Repository\ProductTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Surface technology of an advertising structure: static, prismatron, video screen.
 */
#[ORM\Entity(repositoryClass: ProductTypeRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('name', message: 'Тип с таким названием уже есть')]
class ProductType
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Whether a side is booked per month or sold as airtime (video screens).
     */
    #[ORM\Column(length: 20, enumType: BookingMode::class, options: ['default' => 'side'])]
    #[Assert\NotNull]
    private BookingMode $bookingMode = BookingMode::Side;

    /**
     * Categories (formats) this type is available for.
     *
     * @var Collection<int, Category>
     */
    #[ORM\ManyToMany(targetEntity: Category::class, inversedBy: 'productTypes')]
    private Collection $categories;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBookingMode(): BookingMode
    {
        return $this->bookingMode;
    }

    public function setBookingMode(BookingMode $bookingMode): static
    {
        $this->bookingMode = $bookingMode;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
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
}
