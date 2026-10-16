<?php

namespace App\Entity;

use App\Enum\BookingKind;
use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use App\Service\MonthCalendar;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Booking of a structure side for an inclusive period of days.
 * Whole sides (static, prismatron) are booked for whole calendar months; airtime (video) sides for any days
 * (two weeks at least), $slots slots of the screen's block.
 *
 * Created as a 24h hold via BookingManager; a hold that is not confirmed in time
 * stops blocking the side at $expiresAt and is later marked Expired.
 * A confirmed booking holds the side for good; whether the client has paid is tracked apart ($paidAt),
 * since post-paying clients get their booking confirmed before they pay.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_booking_side_period', columns: ['side_id', 'start_date', 'end_date'])]
#[ORM\Index(name: 'idx_booking_status_expires', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'idx_booking_client', columns: ['client_id'])]
class Booking
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductSide $side;

    /** First booked day */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    /** Last booked day (inclusive) */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    /** Slots of the screen's block taken by an airtime booking; null for whole-side bookings */
    #[ORM\Column(nullable: true)]
    private ?int $slots;

    /**
     * Seconds of every taken slot the client gets: 5 of a 10-second slot (the other half goes to someone else)
     * or the whole slot; null = the whole slot (see ProductSide::secondsTakenBy()).
     */
    #[ORM\Column(nullable: true)]
    private ?int $slotSeconds = null;

    #[ORM\Column(length: 20, enumType: BookingStatus::class)]
    private BookingStatus $status = BookingStatus::Hold;

    /** When an unconfirmed hold stops blocking the side; null once confirmed */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    /** When the client paid; null = not paid yet (a post-paying client's confirmed booking stays unpaid for a while) */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /**
     * Whose the booking is: the client card the side is taken for. Required to book (see BookingRequest),
     * but nullable in the database so deleting an account doesn't take the booking history with it.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $client;

    /** Contact person of this booking; the one from the client card when the manager left it empty */
    #[ORM\Column(length: 255)]
    private string $clientName;

    #[ORM\Column(length: 50)]
    private string $clientPhone;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    /** Commercial, social advertising, a certificate or a bonus: only a commercial booking is sold for money */
    #[ORM\Column(length: 20, enumType: BookingKind::class, options: ['default' => 'commercial'])]
    private BookingKind $kind = BookingKind::Commercial;

    /** The sum of the certificate the booking is placed against, rubles; apart from the money. Certificates only */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $certificateAmount = null;

    /** What the booking was sold for in the end, rubles with kopecks for the whole period (discounts and bargaining included); null = not set yet */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $soldPrice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy;

    /**
     * One-off services sold with the booking: layouts, printing, mounting.
     *
     * @var Collection<int, BookingServiceLine>
     */
    #[ORM\OneToMany(targetEntity: BookingServiceLine::class, mappedBy: 'booking', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $serviceLines;

    /**
     * Payment schedule of the booking (PaymentScheduler::scheduleBooking()): when the client pays, in the payment calendar.
     *
     * @var Collection<int, Payment>
     */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'booking')]
    #[ORM\OrderBy(['dueDate' => 'ASC', 'id' => 'ASC'])]
    private Collection $payments;

    public function __construct(
        ProductSide $side,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        ?int $slots,
        ?User $client,
        string $clientName,
        string $clientPhone,
        ?string $comment = null,
        ?User $createdBy = null,
        ?int $slotSeconds = null,
    ) {
        $this->side = $side;
        $this->startDate = $startDate->setTime(0, 0);
        $this->endDate = $endDate->setTime(0, 0);
        $this->slots = $slots;
        $this->client = $client;
        $this->clientName = $clientName;
        $this->clientPhone = $clientPhone;
        $this->comment = $comment;
        $this->createdBy = $createdBy;
        $this->slotSeconds = null !== $slots ? $slotSeconds : null;
        $this->serviceLines = new ArrayCollection();
        $this->payments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSide(): ProductSide
    {
        return $this->side;
    }

    public function getProduct(): ?Product
    {
        return $this->side->getProduct();
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getDays(): int
    {
        return MonthCalendar::days($this->startDate, $this->endDate);
    }

    /** Booked for whole calendar months (the 1st to the last day) */
    public function isWholeMonths(): bool
    {
        return MonthCalendar::isWholeMonths($this->startDate, $this->endDate);
    }

    public function getMonthCount(): int
    {
        $diff = $this->startDate->diff($this->endDate->modify('+1 day'));

        return $diff->y * 12 + $diff->m;
    }

    /** The booking shares at least one day with [$from, $to] */
    public function overlaps(\DateTimeInterface $from, \DateTimeInterface $to): bool
    {
        return $this->startDate->format('Y-m-d') <= $to->format('Y-m-d') && $this->endDate->format('Y-m-d') >= $from->format('Y-m-d');
    }

    public function covers(\DateTimeInterface $day): bool
    {
        return $this->overlaps($day, $day);
    }

    public function getSlots(): ?int
    {
        return $this->slots;
    }

    /** Another number of slots for an airtime booking; checked by BookingManager::changeSlots() */
    public function changeSlots(int $slots): void
    {
        if (null === $this->slots) {
            throw new \LogicException('A whole-side booking has no slots.');
        }
        $this->slots = $slots;
    }

    public function getSlotSeconds(): ?int
    {
        return $this->slotSeconds;
    }

    /**
     * Another side (of the same structure), period or airtime; checked by BookingManager::update().
     * $slots = null for a whole side.
     */
    public function reschedule(ProductSide $side, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate, ?int $slots, ?int $slotSeconds = null): void
    {
        $this->side = $side;
        $this->startDate = $startDate->setTime(0, 0);
        $this->endDate = $endDate->setTime(0, 0);
        $this->slots = $slots;
        $this->slotSeconds = null !== $slots ? $slotSeconds : null;
    }

    /** Who the side is taken by, the contact of this booking and the manager's comment */
    public function changeClient(?User $client, string $clientName, string $clientPhone, ?string $comment): void
    {
        $this->client = $client;
        $this->clientName = $clientName;
        $this->clientPhone = $clientPhone;
        $this->comment = $comment;
    }

    public function getStatus(): BookingStatus
    {
        return $this->status;
    }

    /**
     * Status as of $now: a hold past its deadline is reported as expired
     * even before the scheduled cleanup has updated the row.
     */
    public function getStatusAt(\DateTimeInterface $now): BookingStatus
    {
        return $this->isHoldOverdue($now) ? BookingStatus::Expired : $this->status;
    }

    /**
     * Whether the booking blocks the side/airtime at $now.
     */
    public function isActiveAt(\DateTimeInterface $now): bool
    {
        return BookingStatus::Confirmed === $this->status
            || (BookingStatus::Hold === $this->status && !$this->isHoldOverdue($now));
    }

    public function isHoldOverdue(\DateTimeInterface $now): bool
    {
        return BookingStatus::Hold === $this->status && null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function hold(\DateTimeImmutable $until): void
    {
        $this->status = BookingStatus::Hold;
        $this->expiresAt = $until;
    }

    /** The side is the client's for good: no longer released automatically, paid or not */
    public function confirm(\DateTimeImmutable $at): void
    {
        $this->status = BookingStatus::Confirmed;
        $this->confirmedAt ??= $at;
        $this->expiresAt = null;
    }

    /** Money came in; a hold paid for is confirmed at once */
    public function markPaid(\DateTimeImmutable $at): void
    {
        if (BookingStatus::Hold === $this->status) {
            $this->confirm($at);
        }
        $this->paidAt = $at;
    }

    public function markUnpaid(): void
    {
        $this->paidAt = null;
    }

    public function isPaid(): bool
    {
        return null !== $this->paidAt;
    }

    public function cancel(): void
    {
        $this->status = BookingStatus::Cancelled;
        $this->expiresAt = null;
    }

    public function expire(): void
    {
        $this->status = BookingStatus::Expired;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    /** The client card's own title, the typed-in name when the account is gone */
    public function getClientTitle(): string
    {
        return $this->client?->getClientTitle() ?? $this->clientName;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getClientPhone(): string
    {
        return $this->clientPhone;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getSoldPrice(): ?float
    {
        return null === $this->soldPrice ? null : (float) $this->soldPrice;
    }

    /**
     * The part of the sold price that falls on the period, by days (the shipment of the period): a booking for
     * September–November sold for 90 000 brings 30 000 to October; the months add up to the price exactly.
     * Null when no price is set; 0 for a booking not sold for money (social advertising, a bonus, a certificate).
     */
    public function getSoldPriceWithin(\DateTimeInterface $from, \DateTimeInterface $to): ?float
    {
        if (!$this->kind->isSold()) {
            return 0.0; // free or a certificate: no money, and not "without a price" either
        }

        return $this->shareWithin($this->soldPrice, $from, $to);
    }

    /** The part of the certificate's sum that falls on the period, by days like the price; 0 for other kinds */
    public function getCertificateWithin(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return BookingKind::Certificate === $this->kind ? $this->shareWithin($this->certificateAmount, $from, $to) ?? 0.0 : 0.0;
    }

    /**
     * Counted as "by the last day" minus "by the day before the first", each in whole kopecks,
     * so the months of a booking add up to $amount exactly. Null when there is no amount.
     */
    private function shareWithin(?string $amount, \DateTimeInterface $from, \DateTimeInterface $to): ?float
    {
        if (null === $amount) {
            return null;
        }
        $days = $this->getDaysWithin($from, $to);
        if (0 === $days) {
            return 0.0;
        }
        $before = $this->getDaysWithin($this->startDate, (new \DateTimeImmutable($from->format('Y-m-d')))->modify('-1 day'));
        $kopecks = (int) round((float) $amount * 100);
        $total = $this->getDays();
        $shipped = static fn (int $daysGone) => (int) round($kopecks * $daysGone / $total);

        return ($shipped($before + $days) - $shipped($before)) / 100;
    }

    /** Booked days that fall on the period [$from, $to] */
    public function getDaysWithin(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $start = max($this->startDate->format('Y-m-d'), $from->format('Y-m-d'));
        $end = min($this->endDate->format('Y-m-d'), $to->format('Y-m-d'));

        return $start > $end ? 0 : MonthCalendar::days(new \DateTimeImmutable($start), new \DateTimeImmutable($end));
    }

    /** Services are one-off: they are shipped whole on the first day of the booking */
    public function getServicesWithin(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $start = $this->startDate->format('Y-m-d');

        return $start >= $from->format('Y-m-d') && $start <= $to->format('Y-m-d') ? $this->getServicesTotal() : 0.0;
    }

    /** The price of the placement as money: "Продано за" of a commercial booking, 0 for the other kinds */
    public function getPlacementPrice(): ?float
    {
        return $this->kind->isSold() ? $this->getSoldPrice() : 0.0;
    }

    public function getKind(): BookingKind
    {
        return $this->kind;
    }

    public function getCertificateAmount(): ?float
    {
        return null === $this->certificateAmount ? null : (float) $this->certificateAmount;
    }

    /**
     * Places the booking as $kind: only a commercial booking keeps "Продано за", only a certificate keeps its sum.
     */
    public function changeKind(BookingKind $kind, ?float $certificateAmount = null): static
    {
        $this->kind = $kind;
        $this->certificateAmount = BookingKind::Certificate === $kind && null !== $certificateAmount ? number_format($certificateAmount, 2, '.', '') : null;
        if (!$kind->isSold()) {
            $this->soldPrice = null;
        }

        return $this;
    }

    /** Something to pay for: a commercial booking, or services sold with a booking of another kind */
    public function needsPayment(): bool
    {
        return $this->kind->isSold() || $this->getServicesTotal() > 0;
    }

    public function setSoldPrice(?float $soldPrice): static
    {
        $this->soldPrice = null === $soldPrice ? null : number_format($soldPrice, 2, '.', '');

        return $this;
    }

    /**
     * @return Collection<int, BookingServiceLine>
     */
    public function getServiceLines(): Collection
    {
        return $this->serviceLines;
    }

    public function addServiceLine(BookingServiceLine $line): static
    {
        if (!$this->serviceLines->contains($line)) {
            $line->setPosition(\count($this->serviceLines))->setBooking($this);
            $this->serviceLines->add($line);
        }

        return $this;
    }

    public function removeServiceLine(BookingServiceLine $line): static
    {
        $this->serviceLines->removeElement($line);

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
        return round(array_sum($this->payments->map(static fn (Payment $payment) => (float) $payment->getAmount())->toArray()), 2);
    }

    /** Every scheduled payment has come; false without a schedule */
    public function isScheduleSettled(): bool
    {
        return !$this->payments->isEmpty() && $this->payments->forAll(static fn (int $i, Payment $payment) => $payment->isPaid());
    }

    /** Services sold with the booking, rubles; apart from the sold price of the placement */
    public function getServicesTotal(): float
    {
        return round(array_sum($this->serviceLines->map(static fn (BookingServiceLine $line) => $line->getTotal())->toArray()), 2);
    }
}
