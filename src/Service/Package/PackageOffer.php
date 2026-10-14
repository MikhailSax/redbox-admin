<?php

namespace App\Service\Package;

use App\Entity\Package;

/**
 * A package for one period: its positions with the sides free all the period.
 */
final readonly class PackageOffer
{
    /**
     * @param list<SlotOffer> $slots in the package's order
     */
    public function __construct(
        public Package $package,
        public \DateTimeImmutable $from,
        /** Last day, inclusive */
        public \DateTimeImmutable $to,
        public int $months,
        public array $slots,
    ) {
    }

    /** Every position has enough free sides for a set */
    public function isAvailable(): bool
    {
        foreach ($this->slots as $slot) {
            if ([] === $slot->suggested) {
                return false;
            }
        }

        return [] !== $this->slots;
    }

    /** Price per month of the ready set, null when there is none */
    public function monthlyTotal(): ?float
    {
        return $this->isAvailable() ? array_sum(array_map(static fn (SlotOffer $slot) => $slot->suggestedTotal(), $this->slots)) : null;
    }

    /** The same sides at their list prices per month: what the client saves is the difference */
    public function listMonthlyTotal(): ?float
    {
        return $this->isAvailable() ? array_sum(array_map(static fn (SlotOffer $slot) => $slot->suggestedListTotal(), $this->slots)) : null;
    }
}
