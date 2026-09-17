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
        /** Key of ProductHelper::SIZES */
        #[Assert\Length(max: 20)]
        public ?string $size = null,
        /** "own" for Redbox's structures or a partner id */
        #[Assert\Regex('/^(own|\d+)$/')]
        public ?string $owner = null,
        /** Month the status is shown for, "YYYY-MM"; current month by default */
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/')]
        public ?string $month = null,
        #[Assert\Positive]
        public int $page = 1,
    ) {
    }

    public function statusFilter(): ?AvailabilityStatus
    {
        return null !== $this->status && '' !== $this->status ? AvailabilityStatus::from($this->status) : null;
    }
}
