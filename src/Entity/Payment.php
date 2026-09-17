<?php

namespace App\Entity;

use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A payment a client has to make by a date: one line of the payment calendar.
 * Usually one month of a media plan (PaymentScheduler), or added by hand. The status is derived from the dates.
 */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_payment_due', columns: ['due_date', 'paid_at'])]
class Payment
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Выберите клиента')]
    private ?User $client = null;

    /** The media plan the payment is for; the payment stays with the client if the plan is deleted */
    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MediaPlan $mediaPlan = null;

    /** What the payment is for: "Медиаплан «Осень» — октябрь 2026" */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите, за что платёж', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /** Rubles */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotBlank(message: 'Укажите сумму')]
    #[Assert\Positive(message: 'Сумма должна быть больше нуля')]
    private ?string $amount = null;

    /** The client must pay by the end of this day */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Укажите срок оплаты')]
    private ?\DateTimeImmutable $dueDate = null;

    /** When the money came; null = not paid yet */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function statusAt(\DateTimeImmutable $now): PaymentStatus
    {
        $today = $now->setTime(0, 0);

        return match (true) {
            null !== $this->paidAt => PaymentStatus::Paid,
            $this->dueDate < $today => PaymentStatus::Overdue,
            $this->dueDate <= $today->modify(\sprintf('+%d days', PaymentStatus::SOON_DAYS)) => PaymentStatus::DueSoon,
            default => PaymentStatus::Upcoming,
        };
    }

    /** Whole days past the due date (0 when not overdue) */
    public function daysOverdueAt(\DateTimeImmutable $now): int
    {
        if (null !== $this->paidAt || null === $this->dueDate) {
            return 0;
        }

        return max(0, (int) $this->dueDate->diff($now->setTime(0, 0))->format('%r%a'));
    }

    public function markPaid(\DateTimeImmutable $at): static
    {
        $this->paidAt = $at;

        return $this;
    }

    public function markUnpaid(): static
    {
        $this->paidAt = null;

        return $this;
    }

    public function isPaid(): bool
    {
        return null !== $this->paidAt;
    }

    #[Assert\Callback]
    public function validateMediaPlan(ExecutionContextInterface $context): void
    {
        $planClient = $this->mediaPlan?->getClient();
        if (null !== $planClient && null !== $this->client && $planClient !== $this->client) {
            $context->buildViolation(\sprintf('Медиаплан оформлен на другого клиента — %s', $planClient->getClientTitle()))->atPath('mediaPlan')->addViolation();
        }
        if (null !== $this->client && !$this->client->isEmailVerified()) {
            $context->buildViolation(User::UNVERIFIED_MESSAGE)->atPath('client')->addViolation();
        }
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

    public function getMediaPlan(): ?MediaPlan
    {
        return $this->mediaPlan;
    }

    public function setMediaPlan(?MediaPlan $mediaPlan): static
    {
        $this->mediaPlan = $mediaPlan;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = null !== $title ? trim($title) : null;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate?->setTime(0, 0);

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = null !== $comment && '' !== trim($comment) ? trim($comment) : null;

        return $this;
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
}
