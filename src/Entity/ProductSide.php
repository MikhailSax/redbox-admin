<?php

namespace App\Entity;

use App\Enum\BookingMode;
use App\Repository\ProductSideRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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

    /** Price for two weeks (the shortest placement) as a whole; null = the month price counted by days */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $price2Weeks = null;

    /** Price per month when placed for 3 months or more; null = the month price */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $price3Months = null;

    /** Price per month when placed for 6 months or more; null = the 3-month price */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $price6Months = null;

    /** Printing the banner / film / backlit for this side, rubles; a one-off service */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной')]
    private ?string $printPrice = null;

    /** What is printed: "баннер", "плёнка", "бэклит" */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $printNote = null;

    /**
     * OTS (opportunities to see): how many times a day the side is seen, by traffic counts or research data;
     * null = not known. The reach of media plans is counted from it.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'OTS не может быть отрицательным')]
    private ?int $dailyOts = null;

    /** Video screens: length of one slot, seconds (BookingMode::SLOT_DURATIONS) */
    #[ORM\Column(options: ['default' => BookingMode::DEFAULT_SLOT_SECONDS])]
    #[Assert\Choice(choices: BookingMode::SLOT_DURATIONS, message: 'Слот — 5 или 10 секунд')]
    private int $slotSeconds = BookingMode::DEFAULT_SLOT_SECONDS;

    /** Video screens: slots of the block on sale; the screen is taken when every one of them is booked */
    #[ORM\Column(options: ['default' => BookingMode::DEFAULT_SLOT_COUNT])]
    #[Assert\Range(notInRangeMessage: 'Слотов — от {{ min }} до {{ max }}', min: 1, max: BookingMode::MAX_SLOT_COUNT)]
    private int $slotCount = BookingMode::DEFAULT_SLOT_COUNT;

    /**
     * Video screens: every slot of the block, those not on sale (the owner's own clips, social ads) included;
     * null = as many as are on sale. A client's share of the airtime, and so the contacts, is counted from it.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(notInRangeMessage: 'Слотов — от {{ min }} до {{ max }}', min: 1, max: BookingMode::MAX_SLOT_COUNT)]
    private ?int $loopSlotCount = null;

    /**
     * False while the side is out of order (repairs, the screen is off, no permit): it is left out of the dashboard,
     * the website and media plans, and takes no new bookings. Bookings it already has stay.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $working = true;

    /** Why the side doesn't work; only kept while it doesn't */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $notWorkingReason = null;

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

    public function getPrice2Weeks(): ?string
    {
        return $this->price2Weeks;
    }

    public function setPrice2Weeks(?string $price2Weeks): static
    {
        $this->price2Weeks = $price2Weeks;

        return $this;
    }

    public function getPrice3Months(): ?string
    {
        return $this->price3Months;
    }

    public function setPrice3Months(?string $price3Months): static
    {
        $this->price3Months = $price3Months;

        return $this;
    }

    public function getPrice6Months(): ?string
    {
        return $this->price6Months;
    }

    public function setPrice6Months(?string $price6Months): static
    {
        $this->price6Months = $price6Months;

        return $this;
    }

    /**
     * Price per month (per slot for a screen) for a placement of $months months:
     * the 6- or 3-month price when the side has one, otherwise the month price.
     */
    public function getMonthlyPriceFor(int $months): ?string
    {
        return match (true) {
            $months >= 6 && null !== ($this->price6Months ?? $this->price3Months) => $this->price6Months ?? $this->price3Months,
            $months >= 3 && null !== $this->price3Months => $this->price3Months,
            default => $this->getEffectivePrice(),
        };
    }

    /**
     * Price per month for a placement of $days days. Shorter than four weeks with a two-week price:
     * that price scaled to 30 days, so 14 days cost exactly the two-week price.
     */
    public function getMonthlyPriceForDays(int $days): ?string
    {
        if ($days < 28 && null !== $this->price2Weeks) {
            return number_format((float) $this->price2Weeks * 30 / BookingMode::MIN_DAYS, 2, '.', '');
        }

        return $this->getMonthlyPriceFor(max(1, (int) round($days / 30)));
    }

    public function getPrintPrice(): ?string
    {
        return $this->printPrice;
    }

    public function setPrintPrice(?string $printPrice): static
    {
        $this->printPrice = $printPrice;

        return $this;
    }

    public function getPrintNote(): ?string
    {
        return $this->printNote;
    }

    public function setPrintNote(?string $printNote): static
    {
        $printNote = null !== $printNote ? trim($printNote) : null;
        $this->printNote = '' !== $printNote ? $printNote : null;

        return $this;
    }

    public function getDailyOts(): ?int
    {
        return $this->dailyOts;
    }

    public function setDailyOts(?int $dailyOts): static
    {
        $this->dailyOts = $dailyOts;

        return $this;
    }

    /**
     * Contacts a day with one client's advertising: the side's whole OTS; on a screen the share of the whole block
     * the client's airtime takes (1 slot of 12 is shown 1/12 of the time, half a slot 1/24, even when only 9 of
     * the 12 are on sale). Null when the OTS is not known.
     */
    public function getDailyContacts(?int $slots, ?int $seconds = null): ?float
    {
        if (null === $this->dailyOts) {
            return null;
        }

        return $this->isAirtime() ? $this->dailyOts * min($this->airtimeSeconds($slots ?? 1, $seconds), $this->getBlockSeconds()) / max(1, $this->getLoopSeconds()) : (float) $this->dailyOts;
    }

    public function getSlotSeconds(): int
    {
        return $this->slotSeconds;
    }

    public function setSlotSeconds(int $slotSeconds): static
    {
        $this->slotSeconds = $slotSeconds;

        return $this;
    }

    public function getSlotCount(): int
    {
        return $this->slotCount;
    }

    public function setSlotCount(int $slotCount): static
    {
        $this->slotCount = $slotCount;

        return $this;
    }

    public function getLoopSlotCount(): ?int
    {
        return $this->loopSlotCount;
    }

    public function setLoopSlotCount(?int $loopSlotCount): static
    {
        $this->loopSlotCount = $loopSlotCount;

        return $this;
    }

    #[Assert\Callback]
    public function validateLoopSlotCount(ExecutionContextInterface $context): void
    {
        if (null !== $this->loopSlotCount && $this->loopSlotCount < $this->slotCount) {
            $context->buildViolation('Всего слотов в блоке не может быть меньше, чем в продаже')->atPath('loopSlotCount')->addViolation();
        }
    }

    /** Airtime of the screen's block on sale, seconds: every slot on sale once. The screen is taken when all of it is. */
    public function getBlockSeconds(): int
    {
        return $this->slotSeconds * $this->slotCount;
    }

    /** Length of the whole block, seconds, the slots not on sale included */
    public function getLoopSeconds(): int
    {
        return $this->slotSeconds * max($this->slotCount, $this->loopSlotCount ?? 0);
    }

    /**
     * Seconds of the block a booking of this side takes on each of its days: its slots × the seconds it has
     * in each of them, or the whole block for a whole-side booking. The screen is taken when the whole block is.
     * On a side no longer sold as airtime (a screen turned into a static poster) every booking takes the whole side.
     */
    public function secondsTakenBy(Booking $booking): int
    {
        return null !== $booking->getSlots() && $this->isAirtime() ? $this->airtimeSeconds($booking->getSlots(), $booking->getSlotSeconds()) : $this->getBlockSeconds();
    }

    /** $slots slots with $seconds of each (null = whole slots) */
    public function airtimeSeconds(int $slots, ?int $seconds = null): int
    {
        return $slots * $this->secondsPerSlot($seconds);
    }

    /** What a client gets of each slot: $seconds when it's a valid part of the slot, the whole slot otherwise */
    public function secondsPerSlot(?int $seconds): int
    {
        return null !== $seconds && \in_array($seconds, $this->getSlotSecondsChoices(), true) ? $seconds : $this->slotSeconds;
    }

    /**
     * How much of a slot a client may take: the whole slot or a part of it that is a slot length of its own
     * (a 10-second slot is sold whole or as two 5-second halves).
     *
     * @return list<int> seconds, the whole slot first
     */
    public function getSlotSecondsChoices(): array
    {
        return BookingMode::secondsChoices($this->slotSeconds);
    }

    public function isWorking(): bool
    {
        return $this->working;
    }

    public function setWorking(bool $working): static
    {
        $this->working = $working;
        if ($working) {
            $this->notWorkingReason = null;
        }

        return $this;
    }

    public function getNotWorkingReason(): ?string
    {
        return $this->notWorkingReason;
    }

    public function setNotWorkingReason(?string $reason): static
    {
        $reason = null !== $reason ? trim($reason) : null;
        $this->notWorkingReason = !$this->working && '' !== $reason ? $reason : null;

        return $this;
    }

    /** "Сторона В (ремонт)": for messages */
    public function getNotWorkingLabel(): string
    {
        return \sprintf('%s, сторона %s%s', $this->product?->getName(), $this->name, null !== $this->notWorkingReason ? ' ('.$this->notWorkingReason.')' : '');
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
