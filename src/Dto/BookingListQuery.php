<?php

namespace App\Dto;

use App\Enum\BookingStatus;
use App\Repository\BookingRepository;
use App\Service\MonthCalendar;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filters and sorting of the admin bookings list, bound from the query string.
 */
final readonly class BookingListQuery
{
    /** Not a status: confirmed bookings of post-paying clients still waiting for the money */
    public const UNPAID = 'unpaid';

    public const KIND_AIRTIME = 'airtime';
    public const KIND_SIDE = 'side';

    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $q = null,
        /** A BookingStatus value or "unpaid"; anything else lists every status */
        #[Assert\Length(max: 20)]
        public ?string $status = null,
        /** "YYYY-MM": bookings that take at least a day of the month */
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/')]
        public ?string $month = null,
        /** "airtime" — screens sold by days, "side" — whole sides by months */
        #[Assert\Choice(choices: [self::KIND_AIRTIME, self::KIND_SIDE])]
        public ?string $kind = null,
        /** Id of the user who made the booking */
        #[Assert\Positive]
        public ?int $author = null,
        /** A key of BookingRepository::LIST_SORTS; an unknown one sorts by the creation date */
        #[Assert\Length(max: 20)]
        public ?string $sort = null,
        /** "asc" | "desc"; the default direction of the column otherwise */
        #[Assert\Length(max: 4)]
        public ?string $dir = null,
    ) {
    }

    public function isUnpaid(): bool
    {
        return self::UNPAID === $this->status;
    }

    public function statusFilter(): ?BookingStatus
    {
        return null !== $this->status && !$this->isUnpaid() ? BookingStatus::tryFrom($this->status) : null;
    }

    public function monthFilter(): ?\DateTimeImmutable
    {
        return null !== $this->month && '' !== $this->month ? MonthCalendar::parse($this->month) : null;
    }

    public function sortKey(): string
    {
        return isset(BookingRepository::LIST_SORTS[(string) $this->sort]) ? $this->sort : 'created';
    }

    /** By the creation date the newest come first, by any other column A to Z, the earliest first */
    public function isDescending(): bool
    {
        return 'desc' === $this->dir || ('asc' !== $this->dir && 'created' === $this->sortKey());
    }
}
