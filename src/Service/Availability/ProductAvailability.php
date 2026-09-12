<?php

namespace App\Service\Availability;

use App\Enum\AvailabilityStatus;

/**
 * Status of a structure for a month, aggregated from its sides:
 * free if any side can still be sold, otherwise booked if any side is on hold, otherwise occupied.
 */
final readonly class ProductAvailability
{
    /**
     * @param list<SideAvailability> $sides
     */
    public function __construct(
        public int $productId,
        public \DateTimeImmutable $month,
        public array $sides,
    ) {
    }

    /**
     * null when the structure has no sides (nothing to sell).
     */
    public function status(): ?AvailabilityStatus
    {
        if ([] === $this->sides) {
            return null;
        }

        $statuses = array_map(static fn (SideAvailability $side) => $side->status(), $this->sides);
        foreach ([AvailabilityStatus::Free, AvailabilityStatus::Booked] as $candidate) {
            if (\in_array($candidate, $statuses, true)) {
                return $candidate;
            }
        }

        return AvailabilityStatus::Occupied;
    }

    public function side(int $sideId): ?SideAvailability
    {
        foreach ($this->sides as $side) {
            if ($side->sideId === $sideId) {
                return $side;
            }
        }

        return null;
    }
}
