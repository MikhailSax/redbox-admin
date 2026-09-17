<?php

namespace App\Service\Availability;

use App\Enum\AvailabilityStatus;
use App\Enum\BookingMode;

/**
 * Occupancy of one side for one month.
 */
final readonly class SideAvailability
{
    public function __construct(
        public int $sideId,
        public string $sideName,
        public bool $airtime,
        /** Seconds of the loop taken by paid bookings (a whole-side booking counts as the full loop) */
        public int $paidSeconds,
        /** Seconds taken by unpaid holds that have not expired yet */
        public int $holdSeconds,
    ) {
    }

    public function usedSeconds(): int
    {
        return min(BookingMode::LOOP_SECONDS, $this->paidSeconds + $this->holdSeconds);
    }

    public function freeSeconds(): int
    {
        return BookingMode::LOOP_SECONDS - $this->usedSeconds();
    }

    /** How loaded the screen's loop is, 0..100 (busiest day of the month) */
    public function loadPercent(): int
    {
        return BookingMode::loadPercent($this->usedSeconds());
    }

    public function status(): AvailabilityStatus
    {
        $soldOut = $this->airtime
            ? $this->freeSeconds() < min(BookingMode::CLIP_DURATIONS) // not even the shortest clip fits
            : $this->paidSeconds + $this->holdSeconds > 0;

        return match (true) {
            !$soldOut => AvailabilityStatus::Free,
            $this->holdSeconds > 0 => AvailabilityStatus::Booked,
            default => AvailabilityStatus::Occupied,
        };
    }
}
