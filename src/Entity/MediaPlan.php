<?php

namespace App\Entity;

use App\Repository\MediaPlanRepository;
use App\Service\MonthCalendar;
use App\Validator\RunningPromoCode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Commercial proposal for a client: a selection of structure sides for one period, with prices.
 * Exported to PDF and can be turned into bookings in one go (MediaPlanManager).
 */
#[ORM\Entity(repositoryClass: MediaPlanRepository::class)]
#[ORM\HasLifecycleCallbacks]
class MediaPlan
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

    /** The client as printed in the PDF; taken from $client when left empty (see validateClient()) */
    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private ?string $clientName = null;

    /** Phone and/or email, free text */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $clientContact = null;

    /** First day of the first month */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Выберите месяц')]
    private ?\DateTimeImmutable $startMonth = null;

    #[ORM\Column]
    #[Assert\Range(notInRangeMessage: 'Срок — от {{ min }} до {{ max }} месяцев', min: 1, max: 12)]
    private int $months = 1;

    #[ORM\Column]
    #[Assert\Range(notInRangeMessage: 'Скидка — от {{ min }} до {{ max }}%', min: 0, max: 90)]
    private int $discountPercent = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Unlocks promotions that require this code; stored upper-case */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[RunningPromoCode]
    private ?string $promoCode = null;

    /** The client's first order: unlocks "first order" promotions */
    #[ORM\Column(options: ['default' => false])]
    private bool $firstOrder = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * @var Collection<int, MediaPlanItem>
     */
    #[ORM\OneToMany(targetEntity: MediaPlanItem::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    /**
     * One-off services: layout design, banner printing, mounting…
     *
     * @var Collection<int, MediaPlanServiceLine>
     */
    #[ORM\OneToMany(targetEntity: MediaPlanServiceLine::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $serviceLines;

    /** The client account the plan is for: needed to schedule its payments. $clientName stays the name printed in the PDF */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $client = null;

    /**
     * Payment schedule of the plan (PaymentScheduler); payments stay with the client when the plan is deleted.
     *
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'mediaPlan')]
    #[ORM\OrderBy(['dueDate' => 'ASC', 'id' => 'ASC'])]
    private Collection $payments;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->serviceLines = new ArrayCollection();
        $this->payments = new ArrayCollection();
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /** Sum of the scheduled payments */
    public function getScheduledTotal(): float
    {
        return array_sum($this->payments->map(static fn (Payment $payment) => (float) $payment->getAmount())->toArray());
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

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function setClientName(?string $clientName): static
    {
        $this->clientName = null !== $clientName && '' !== trim($clientName) ? trim($clientName) : null;

        return $this;
    }

    #[Assert\Callback]
    public function validateClient(ExecutionContextInterface $context): void
    {
        if (null === $this->clientName && null === $this->client) {
            $context->buildViolation('Укажите клиента: выберите из списка или впишите название')->atPath('clientName')->addViolation();
        }
        if (null !== $this->client && !$this->client->isEmailVerified()) {
            $context->buildViolation(User::UNVERIFIED_MESSAGE)->atPath('client')->addViolation();
        }
    }

    /** An empty "client in the PDF" takes the chosen client's name */
    #[ORM\PreFlush]
    public function fillClientName(): void
    {
        $this->clientName ??= $this->client?->getClientTitle();
    }

    public function getClientContact(): ?string
    {
        return $this->clientContact;
    }

    public function setClientContact(?string $clientContact): static
    {
        $this->clientContact = $clientContact;

        return $this;
    }

    public function getStartMonth(): ?\DateTimeImmutable
    {
        return $this->startMonth;
    }

    public function setStartMonth(?\DateTimeImmutable $startMonth): static
    {
        $this->startMonth = $startMonth;

        return $this;
    }

    public function getEndMonth(): ?\DateTimeImmutable
    {
        return $this->startMonth?->modify(\sprintf('+%d months', $this->months - 1));
    }

    /** Last day of the last month: the plan books whole months */
    public function getEndDate(): ?\DateTimeImmutable
    {
        return null !== $this->startMonth ? MonthCalendar::lastDay($this->getEndMonth()) : null;
    }

    public function getMonths(): int
    {
        return $this->months;
    }

    public function setMonths(int $months): static
    {
        $this->months = $months;

        return $this;
    }

    public function getDiscountPercent(): int
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(int $discountPercent): static
    {
        $this->discountPercent = $discountPercent;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getPromoCode(): ?string
    {
        return $this->promoCode;
    }

    public function setPromoCode(?string $promoCode): static
    {
        $promoCode = null !== $promoCode ? mb_strtoupper(trim($promoCode)) : null;
        $this->promoCode = '' !== $promoCode ? $promoCode : null;

        return $this;
    }

    public function isFirstOrder(): bool
    {
        return $this->firstOrder;
    }

    public function setFirstOrder(bool $firstOrder): static
    {
        $this->firstOrder = $firstOrder;

        return $this;
    }

    /** Placement at list prices (before promotions), before the plan discount */
    public function getListSubtotal(): float
    {
        return array_sum($this->items->map(fn (MediaPlanItem $item) => $item->getBasePrice() * $this->months)->toArray());
    }

    public function getPromotionSavings(): float
    {
        return max(0.0, $this->getListSubtotal() - $this->getPlacementSubtotal());
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * @return Collection<int, MediaPlanItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(MediaPlanItem $item): static
    {
        if (!$this->items->contains($item)) {
            $item->setPosition(\count($this->items));
            $this->items->add($item);
            $item->setPlan($this);
        }

        return $this;
    }

    public function removeItem(MediaPlanItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }

    public function hasSide(ProductSide $side): bool
    {
        return $this->items->exists(static fn (int $key, MediaPlanItem $item) => $item->getSide() === $side);
    }

    /**
     * @return Collection<int, MediaPlanServiceLine>
     */
    public function getServiceLines(): Collection
    {
        return $this->serviceLines;
    }

    public function addServiceLine(MediaPlanServiceLine $line): static
    {
        if (!$this->serviceLines->contains($line)) {
            $line->setPosition(\count($this->serviceLines));
            $this->serviceLines->add($line);
            $line->setPlan($this);
        }

        return $this;
    }

    public function removeServiceLine(MediaPlanServiceLine $line): static
    {
        $this->serviceLines->removeElement($line);

        return $this;
    }

    /*
     * Totals: placement (sides × months) gets the plan discount; services are one-off and not discounted.
     */

    /** Placement for the whole period, before discount */
    public function getPlacementSubtotal(): float
    {
        return array_sum($this->items->map(fn (MediaPlanItem $item) => $item->getMonthlyPrice() * $this->months)->toArray());
    }

    public function getDiscountAmount(): float
    {
        return round($this->getPlacementSubtotal() * $this->discountPercent / 100, 2);
    }

    public function getPlacementTotal(): float
    {
        return $this->getPlacementSubtotal() - $this->getDiscountAmount();
    }

    public function getServicesTotal(): float
    {
        return array_sum($this->serviceLines->map(static fn (MediaPlanServiceLine $line) => $line->getTotal())->toArray());
    }

    /** What the client pays */
    public function getTotal(): float
    {
        return $this->getPlacementTotal() + $this->getServicesTotal();
    }

    /**
     * What Redbox pays partners for the partner sides in the plan (own sides cost nothing).
     */
    public function getPartnerCost(): float
    {
        return array_sum($this->items->map(fn (MediaPlanItem $item) => $item->getMonthlyPartnerCost() * $this->months)->toArray());
    }

    /** Own cost of the services (printing house, installers), where known */
    public function getServicesCost(): float
    {
        return array_sum($this->serviceLines->map(static fn (MediaPlanServiceLine $line) => $line->getCost())->toArray());
    }

    public function getMargin(): float
    {
        return $this->getTotal() - $this->getPartnerCost() - $this->getServicesCost();
    }
}
