<?php

namespace App\Service\Availability;

use App\Enum\AvailabilityStatus;
use App\Enum\BookingMode;

/**
 * Occupancy of one side for one month.
 * A whole side is taken by any booking; a screen when all the time of its block is taken on some day
 * (counted in seconds: half a 10-second slot sold leaves the other half free).
 */
final readonly class SideAvailability
{
    public function __construct(
        public int $sideId,
        public string $sideName,
        public bool $airtime,
        /** Seconds of the block taken by confirmed bookings (a whole-side booking counts as the whole block) */
        public int $confirmedSeconds,
        /** Seconds taken by holds that are neither confirmed nor expired yet */
        public int $holdSeconds,
        /** Slots in the block; 1 for a whole side */
        public int $slotCount = 1,
        /** Length of a slot; 1 for a whole side */
        public int $slotSeconds = 1,
    ) {
    }

    public function blockSeconds(): int
    {
        return $this->slotCount * $this->slotSeconds;
    }

    public function usedSeconds(): int
    {
        return min($this->blockSeconds(), $this->confirmedSeconds + $this->holdSeconds);
    }

    public function freeSeconds(): int
    {
        return $this->blockSeconds() - $this->usedSeconds();
    }

    /** "7", "7,5": slots taken, half slots included */
    public function usedSlotsLabel(): string
    {
        return BookingMode::slotsLabel($this->usedSeconds(), $this->slotSeconds);
    }

    /** "4,5": slots left to sell */
    public function freeSlotsLabel(): string
    {
        return BookingMode::slotsLabel($this->freeSeconds(), $this->slotSeconds);
    }

    /** How full the screen's block is, 0..100 (busiest day of the month) */
    public function loadPercent(): int
    {
        return BookingMode::loadPercent($this->usedSeconds(), $this->blockSeconds());
    }

    public function status(): AvailabilityStatus
    {
        return match (true) {
            $this->freeSeconds() > 0 => AvailabilityStatus::Free,
            $this->holdSeconds > 0 => AvailabilityStatus::Booked,
            default => AvailabilityStatus::Occupied,
        };
    }
}
