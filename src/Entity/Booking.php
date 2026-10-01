<?php

namespace App\Entity;

use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use App\Service\MonthCalendar;
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy;

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

    public function getSlotSeconds(): ?int
    {
        return $this->slotSeconds;
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
}
