<?php

namespace App\Service;

use App\Dto\BookingRequest;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Building media plans and turning them into bookings.
 *
 * Prices: a structure's price is per side per month; for airtime (video) sides it is per 5 s of the loop,
 * so a 15 s clip costs three times the price.
 */
class MediaPlanManager
{
    public const DEFAULT_CLIP = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingManager $bookingManager,
        private readonly ClockInterface $clock,
        private readonly PromotionResolver $promotions,
    ) {
    }

    /**
     * Adds a side at its current price, lowered by the best promotion. Returns null when the side is already in the plan.
     */
    public function addSide(MediaPlan $plan, ProductSide $side, ?int $clipDuration = null): ?MediaPlanItem
    {
        if ($plan->hasSide($side)) {
            return null;
        }

        $clip = $this->bookingManager->isAirtime($side)
            ? (\in_array($clipDuration, BookingMode::CLIP_DURATIONS, true) ? $clipDuration : self::DEFAULT_CLIP)
            : null;

        $item = new MediaPlanItem($side, number_format(self::priceFor($side, $clip), 2, '.', ''), $clip);
        $plan->addItem($item);
        $this->applyBestPromotion($item);
        $plan->touch();
        $this->entityManager->persist($item);

        return $item;
    }

    /**
     * Re-applies promotions to every item whose price the manager hasn't typed by hand
     * (after the promo code, the first order flag or the length changed, or on request).
     *
     * @return int items whose price changed
     */
    public function recalculate(MediaPlan $plan): int
    {
        $changed = 0;
        foreach ($plan->getItems() as $item) {
            if ($item->isManualPrice()) {
                continue;
            }
            $before = $item->getMonthlyPrice();
            $this->applyBestPromotion($item);
            if (abs($before - $item->getMonthlyPrice()) > 0.004) {
                ++$changed;
            }
        }
        $plan->touch();

        return $changed;
    }

    /**
     * Drops the manager's price: back to the list price with promotions.
     */
    public function resetPrice(MediaPlanItem $item): void
    {
        $item->resetManualPrice();
        $this->applyBestPromotion($item);
        $item->getPlan()?->touch();
    }

    private function applyBestPromotion(MediaPlanItem $item): void
    {
        $product = $item->getProduct();
        $plan = $item->getPlan();
        $best = null !== $product && null !== $plan ? $this->promotions->best($product, $item->getBasePrice(), $plan) : null;

        $item->applyPromotion($best['promotion'] ?? null, $best['price'] ?? $item->getBasePrice());
    }

    public static function priceFor(ProductSide $side, ?int $clipDuration): float
    {
        $price = (float) ($side->getEffectivePrice() ?? 0);

        return null !== $clipDuration ? $price * $clipDuration / 5 : $price;
    }

    /**
     * For items not booked yet: why the side can't be booked for the plan period (null = free).
     *
     * @return array<int, string|null> keyed by item id
     */
    public function problems(MediaPlan $plan): array
    {
        $now = $this->clock->now();
        $problems = [];
        foreach ($plan->getItems() as $item) {
            if ($item->getBooking()?->isActiveAt($now)) {
                continue;
            }
            $problems[$item->getId()] = $this->bookingManager->availabilityProblem($item->getSide(), $plan->getStartMonth(), $plan->getEndDate(), $item->getClipDuration());
        }

        return $problems;
    }

    /**
     * Creates a 24h hold for every item that isn't booked yet. Taken sides are skipped and reported.
     *
     * @return array{booked: int, failed: list<string>}
     */
    public function bookAll(MediaPlan $plan, ?User $by): array
    {
        $now = $this->clock->now();
        $booked = 0;
        $failed = [];

        foreach ($plan->getItems() as $item) {
            if ($item->getBooking()?->isActiveAt($now)) {
                continue;
            }

            $request = new BookingRequest();
            $request->side = $item->getSide();
            $request->startMonth = $plan->getStartMonth()->format('Y-m');
            $request->months = $plan->getMonths();
            $request->clipDuration = $item->getClipDuration();
            $request->clientName = (string) $plan->getClientName();
            $request->clientPhone = $plan->getClientContact() ?: '—';
            $request->comment = \sprintf('Медиаплан «%s»', $plan->getTitle());

            try {
                $item->setBooking($this->bookingManager->hold($request, $by));
                ++$booked;
            } catch (BookingException $e) {
                $failed[] = \sprintf('%s: %s', $item->getProduct()?->getName(), $e->getMessage());
            }
        }

        $plan->touch();
        $this->entityManager->flush();

        return ['booked' => $booked, 'failed' => $failed];
    }
}
