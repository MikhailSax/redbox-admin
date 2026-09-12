<?php

namespace App\Service;

use App\Entity\MediaPlan;
use App\Entity\Product;
use App\Entity\Promotion;
use App\Repository\PromotionRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Which promotions apply to a structure today, and the best price they give.
 * Promotions don't stack: the one giving the lowest price wins.
 */
class PromotionResolver
{
    /** @var list<Promotion>|null running promotions, loaded once per request */
    private ?array $running = null;

    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Best promotion for a structure in a media plan (its promo code, first order flag and length count).
     *
     * @return array{promotion: Promotion, price: float}|null null when no promotion lowers the price
     */
    public function best(Product $product, float $basePrice, MediaPlan $plan): ?array
    {
        $best = null;
        foreach ($this->running() as $promotion) {
            if (!$this->appliesInPlan($promotion, $product, $plan)) {
                continue;
            }
            $price = $promotion->apply($basePrice);
            if ($price < $basePrice && (null === $best || $price < $best['price'])) {
                $best = ['promotion' => $promotion, 'price' => $price];
            }
        }

        return $best;
    }

    /**
     * Promotions any client gets for this structure (no promo code, not first-order only) — for badges.
     *
     * @return list<Promotion>
     */
    public function publicFor(Product $product): array
    {
        return array_values(array_filter($this->running(), static fn (Promotion $p) => $p->isPublic() && $p->targets($product)));
    }

    /**
     * Every running promotion that targets the structure, conditional ones included — for the product card.
     *
     * @return list<Promotion>
     */
    public function allFor(Product $product): array
    {
        return array_values(array_filter($this->running(), static fn (Promotion $p) => $p->targets($product)));
    }

    public function appliesInPlan(Promotion $promotion, Product $product, MediaPlan $plan): bool
    {
        return $promotion->targets($product)
            && (null === $promotion->getCode() || $promotion->getCode() === $plan->getPromoCode())
            && (!$promotion->isFirstOrderOnly() || $plan->isFirstOrder())
            && (null === $promotion->getMinMonths() || $plan->getMonths() >= $promotion->getMinMonths());
    }

    /**
     * @return list<Promotion>
     */
    private function running(): array
    {
        return $this->running ??= $this->promotions->findRunning($this->clock->now());
    }
}
