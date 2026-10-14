<?php

namespace App\Entity;

use App\Repository\PackageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "Пакетное предложение": a set of sides sold together at the package's prices.
 *
 * Each slot says how many sides the client takes and from which ones, and at what price per side per month
 * ("Максимум": a super site and 4 screens 6×3 of your choice at 14 800 ₽; "Выгодный": these 4 sides at 11 250 ₽;
 * "Специальный": 2 billboards at the list price for 2–3 months and a screen for free).
 * The website shows the sets that are free for the chosen period, see PackageOffers.
 */
#[ORM\Entity(repositoryClass: PackageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Package
{
    use TimestampableTrait;

    public const MAX_MONTHS = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите название', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /** Shown to the client on the website */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Укажите дату начала')]
    private ?\DateTimeImmutable $startsAt = null;

    /** Last day (inclusive); null = no end date */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'startsAt', message: 'Пакет не может закончиться раньше, чем начнётся')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Shortest placement, months */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Range(notInRangeMessage: 'От {{ min }} до {{ max }} месяцев', min: 1, max: self::MAX_MONTHS)]
    private int $minMonths = 1;

    /** Longest placement, months; null = up to MAX_MONTHS */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'От {{ min }} до {{ max }} месяцев', min: 1, max: self::MAX_MONTHS)]
    private ?int $maxMonths = null;

    /** Order on the website */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /**
     * @var Collection<int, PackageSlot>
     */
    #[ORM\OneToMany(targetEntity: PackageSlot::class, mappedBy: 'package', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Добавьте хотя бы одну позицию')]
    private Collection $slots;

    public function __construct()
    {
        $this->slots = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (null !== $this->maxMonths && $this->maxMonths < $this->minMonths) {
            $context->buildViolation('Не может быть меньше срока «от»')->atPath('maxMonths')->addViolation();
        }
    }

    /**
     * Whether the package is switched on and $date is within its dates.
     */
    public function isRunningOn(\DateTimeInterface $date): bool
    {
        return 'active' === $this->getStateOn($date);
    }

    /**
     * "active" | "scheduled" | "finished" | "disabled"
     */
    public function getStateOn(\DateTimeInterface $date): string
    {
        $day = $date->format('Y-m-d');

        return match (true) {
            !$this->active => 'disabled',
            null === $this->startsAt || $this->startsAt->format('Y-m-d') > $day => 'scheduled',
            null !== $this->endsAt && $this->endsAt->format('Y-m-d') < $day => 'finished',
            default => 'active',
        };
    }

    /** Placement length allowed by the package nearest to $months */
    public function clampMonths(?int $months): int
    {
        return max($this->minMonths, min($this->getMaxMonthsOrDefault(), $months ?? $this->minMonths));
    }

    public function allowsMonths(int $months): bool
    {
        return $months >= $this->minMonths && $months <= $this->getMaxMonthsOrDefault();
    }

    /** "2–3 мес.", "от 2 мес.", "1 мес." */
    public function getMonthsLabel(): string
    {
        return match (true) {
            null === $this->maxMonths => 1 === $this->minMonths ? 'любой срок' : 'от '.$this->minMonths.' мес.',
            $this->maxMonths === $this->minMonths => $this->minMonths.' мес.',
            default => $this->minMonths.'–'.$this->maxMonths.' мес.',
        };
    }

    /** Sides the client takes in all */
    public function getSideCount(): int
    {
        return array_sum($this->slots->map(static fn (PackageSlot $slot) => $slot->getQuantity())->toArray());
    }

    /**
     * Price of the whole package per month when every slot has a package price, null when some slot is at the list price.
     */
    public function getFixedMonthlyTotal(): ?float
    {
        $total = 0.0;
        foreach ($this->slots as $slot) {
            if (null === $slot->getPrice()) {
                return null;
            }
            $total += (float) $slot->getPrice() * $slot->getQuantity();
        }

        return $total;
    }

    public function getSlot(int $id): ?PackageSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->getId() === $id) {
                return $slot;
            }
        }

        return null;
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

    public function getMinMonths(): int
    {
        return $this->minMonths;
    }

    public function setMinMonths(?int $minMonths): static
    {
        $this->minMonths = $minMonths ?? 1;

        return $this;
    }

    public function getMaxMonths(): ?int
    {
        return $this->maxMonths;
    }

    public function getMaxMonthsOrDefault(): int
    {
        return $this->maxMonths ?? self::MAX_MONTHS;
    }

    public function setMaxMonths(?int $maxMonths): static
    {
        $this->maxMonths = $maxMonths;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position ?? 0;

        return $this;
    }

    /**
     * @return Collection<int, PackageSlot>
     */
    public function getSlots(): Collection
    {
        return $this->slots;
    }

    public function addSlot(PackageSlot $slot): static
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->setPackage($this);
        }

        return $this;
    }

    public function removeSlot(PackageSlot $slot): static
    {
        if ($this->slots->removeElement($slot) && $slot->getPackage() === $this) {
            $slot->setPackage(null);
        }

        return $this;
    }
}
