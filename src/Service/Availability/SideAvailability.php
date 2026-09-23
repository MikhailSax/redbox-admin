<?php

namespace App\Service\Availability;

use App\Enum\AvailabilityStatus;
use App\Enum\BookingMode;

/**
 * Occupancy of one side for one month.
 * A whole side is taken by any booking; a screen when every slot of its block is taken on some day.
 */
final readonly class SideAvailability
{
    public function __construct(
        public int $sideId,
        public string $sideName,
        public bool $airtime,
        /** Slots of the block taken by paid bookings (a whole-side booking counts as every slot) */
        public int $paidSlots,
        /** Slots taken by unpaid holds that have not expired yet */
        public int $holdSlots,
        /** Slots in the block; 1 for a whole side */
        public int $slotCount = 1,
    ) {
    }

    public function usedSlots(): int
    {
        return min($this->slotCount, $this->paidSlots + $this->holdSlots);
    }

    public function freeSlots(): int
    {
        return $this->slotCount - $this->usedSlots();
    }

    /** How full the screen's block is, 0..100 (busiest day of the month) */
    public function loadPercent(): int
    {
        return BookingMode::loadPercent($this->usedSlots(), $this->slotCount);
    }

    public function status(): AvailabilityStatus
    {
        return match (true) {
            $this->freeSlots() > 0 => AvailabilityStatus::Free,
            $this->holdSlots > 0 => AvailabilityStatus::Booked,
            default => AvailabilityStatus::Occupied,
        };
    }
}
