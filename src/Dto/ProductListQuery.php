<?php

namespace App\Dto;

use App\Enum\AvailabilityStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filters and page of the admin product list, bound from the query string.
 */
final readonly class ProductListQuery
{
    public const OWNER_OWN = 'own';

    /** Columns the list is sorted by; without one the recently updated come first */
    public const SORTS = ['name', 'district', 'price', 'status'];

    /** Sides the list is filtered by, as named in the cards */
    public const SIDES = ['А', 'В'];

    /** The same letter typed in Latin, or "Б" for "В" as the sides are called aloud */
    private const SIDE_SPELLINGS = ['А' => ['А', 'A'], 'В' => ['В', 'B', 'Б']];

    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $q = null,
        #[Assert\Positive]
        public ?int $category = null,
        #[Assert\Positive]
        public ?int $type = null,
        /** free | booked | occupied, see AvailabilityStatus */
        #[Assert\Choice(choices: ['free', 'booked', 'occupied'])]
        public ?string $status = null,
        #[Assert\Positive]
        public ?int $district = null,
        /** Size key, "6x3", see ProductHelper */
        #[Assert\Length(max: 20)]
        public ?string $size = null,
        /** "own" for Redbox's structures or a partner id */
        #[Assert\Regex('/^(own|\d+)$/')]
        public ?string $owner = null,
        /** "yes" — structures whose every side works, "no" — those with a side out of order; every one by default */
        #[Assert\Choice(choices: ['yes', 'no'])]
        public ?string $working = null,
        /** Month the status is shown for, "YYYY-MM"; current month by default */
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/')]
        public ?string $month = null,
        #[Assert\Positive]
        public int $page = 1,
        /** One of SORTS */
        #[Assert\Choice(choices: self::SORTS)]
        public ?string $sort = null,
        #[Assert\Choice(choices: ['asc', 'desc'])]
        public ?string $dir = null,
        /** Side letter, "А" or "В": structures that have it, their status counted by that side alone */
        #[Assert\Choice(choices: self::SIDES)]
        public ?string $side = null,
    ) {
    }

    /**
     * Side names that count as the chosen side; empty without the filter.
     *
     * @return list<string>
     */
    public function sideNames(): array
    {
        return self::SIDE_SPELLINGS[$this->side] ?? [];
    }

    public function isDescending(): bool
    {
        return 'desc' === $this->dir;
    }

    public function statusFilter(): ?AvailabilityStatus
    {
        return null !== $this->status && '' !== $this->status ? AvailabilityStatus::from($this->status) : null;
    }
}
