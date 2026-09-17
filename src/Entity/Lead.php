<?php

namespace App\Entity;

use App\Enum\LeadStatus;
use App\Repository\LeadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A request for placement: the cart a visitor sent from the website (or a manager wrote down by phone).
 * A manager turns it into a media plan or bookings; nothing is blocked until they do.
 */
#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: 'leads')] // "lead" is a reserved word in MySQL 8 (the LEAD() window function)
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_lead_status_created', columns: ['status', 'created_at'])]
class Lead
{
    use TimestampableTrait;

    public const SOURCE_SITE = 'site';
    public const SOURCE_CRM = 'crm';

    /** Payment terms the visitor asked for */
    public const PAYMENT_PREPAY = 'prepay';
    public const PAYMENT_POSTPAY = 'postpay';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: LeadStatus::class)]
    private LeadStatus $status = LeadStatus::New;

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_SITE;

    /** Set when the visitor was signed in to their personal account */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $client = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Укажите контактное лицо', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private ?string $contactName = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Укажите телефон', normalizer: 'trim')]
    #[Assert\Length(max: 50)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Неверный email')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $companyName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $inn = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $kpp = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $paymentType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Notes a manager keeps while working on the request */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $managerNote = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $assignee = null;

    /** Media plan made out of this request, if any */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MediaPlan $mediaPlan = null;

    /**
     * @var Collection<int, LeadItem>
     */
    #[ORM\OneToMany(targetEntity: LeadItem::class, mappedBy: 'lead', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function __toString(): string
    {
        return \sprintf('Заявка №%d', (int) $this->id);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): LeadStatus
    {
        return $this->status;
    }

    public function setStatus(LeadStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function isFromSite(): bool
    {
        return self::SOURCE_SITE === $this->source;
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

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): static
    {
        $this->contactName = null !== $contactName ? trim($contactName) : null;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = null !== $phone ? trim($phone) : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = null !== $email ? mb_strtolower(trim($email)) : null;
        $this->email = '' !== $email ? $email : null;

        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function setInn(?string $inn): static
    {
        $this->inn = $inn;

        return $this;
    }

    public function getKpp(): ?string
    {
        return $this->kpp;
    }

    public function setKpp(?string $kpp): static
    {
        $this->kpp = $kpp;

        return $this;
    }

    public function getPaymentType(): ?string
    {
        return $this->paymentType;
    }

    public function setPaymentType(?string $paymentType): static
    {
        $this->paymentType = \in_array($paymentType, [self::PAYMENT_PREPAY, self::PAYMENT_POSTPAY], true) ? $paymentType : null;

        return $this;
    }

    public function getPaymentLabel(): ?string
    {
        return match ($this->paymentType) {
            self::PAYMENT_PREPAY => 'Предоплата',
            self::PAYMENT_POSTPAY => 'Постоплата',
            default => null,
        };
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

    public function getManagerNote(): ?string
    {
        return $this->managerNote;
    }

    public function setManagerNote(?string $managerNote): static
    {
        $this->managerNote = $managerNote;

        return $this;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): static
    {
        $this->assignee = $assignee;

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

    /**
     * @return Collection<int, LeadItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(LeadItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setLead($this);
        }

        return $this;
    }

    public function removeItem(LeadItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }

    /** What the visitor saw in the cart; a manager prices the request properly later */
    public function getEstimate(): float
    {
        return array_sum($this->items->map(static fn (LeadItem $item) => $item->getTotal())->toArray());
    }

    /** Client's name for lists: the company, the person, or their account */
    public function getClientTitle(): string
    {
        return $this->companyName ?? $this->client?->getDisplayName() ?? (string) $this->contactName;
    }
}
