<?php

namespace App\Service\Package;

use App\Entity\PackageSlot;
use App\Entity\ProductSide;

/**
 * One position of a package for a period: which of its sides are free, and the ones the ready set takes.
 */
final readonly class SlotOffer
{
    /**
     * @param list<ProductSide> $free      sides free all the period
     * @param list<ProductSide> $suggested the ready set's sides (empty when not enough are free)
     */
    public function __construct(
        public PackageSlot $slot,
        public array $free,
        public array $suggested,
        public int $months,
    ) {
    }

    public function price(ProductSide $side): float
    {
        return $this->slot->priceFor($side, $this->months);
    }

    public static function listPrice(ProductSide $side, int $months): float
    {
        return (float) ($side->getMonthlyPriceFor($months) ?? 0);
    }

    public function suggestedTotal(): float
    {
        return array_sum(array_map($this->price(...), $this->suggested));
    }

    public function suggestedListTotal(): float
    {
        return array_sum(array_map(fn (ProductSide $side) => self::listPrice($side, $this->months), $this->suggested));
    }
}
