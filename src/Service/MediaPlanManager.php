<?php

namespace App\Service;

use App\Dto\BookingRequest;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\ProductSide;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Building media plans and turning them into bookings.
 *
 * Prices: a structure's price is per side per month; for airtime (video) sides it is per slot of the block,
 * so 3 slots cost three times the price. A plan of 3 or 6 months and more takes the side's 3- or 6-month price.
 */
class MediaPlanManager
{
    public const DEFAULT_SLOTS = 1;

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
    public function addSide(MediaPlan $plan, ProductSide $side, ?int $slots = null): ?MediaPlanItem
    {
        if ($plan->hasSide($side)) {
            return null;
        }

        $slots = self::slotsFor($side, $slots);
        $item = new MediaPlanItem($side, self::format(self::priceFor($side, $slots, $plan->getMonths())), $slots);
        $plan->addItem($item);
        $this->applyBestPromotion($item);
        $plan->touch();
        $this->entityManager->persist($item);

        return $item;
    }

    /**
     * Takes the list price anew (the price list and the term may have changed) and re-applies promotions
     * to every item whose price the manager hasn't typed by hand
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
            $item->setBasePrice(self::format(self::priceFor($item->getSide(), $item->getSlots(), $plan->getMonths())));
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
        $item->setBasePrice(self::format(self::priceFor($item->getSide(), $item->getSlots(), (int) $item->getPlan()?->getMonths())));
        $this->applyBestPromotion($item);
        $item->getPlan()?->touch();
    }

    /**
     * Changes the slots of a screen item; the price follows unless the manager typed it.
     */
    public function changeSlots(MediaPlanItem $item, int $slots): void
    {
        $item->setSlots(self::slotsFor($item->getSide(), $slots));
        if (!$item->isManualPrice()) {
            $this->resetPrice($item);
        }
        $item->getPlan()?->touch();
    }

    private function applyBestPromotion(MediaPlanItem $item): void
    {
        $product = $item->getProduct();
        $plan = $item->getPlan();
        $best = null !== $product && null !== $plan ? $this->promotions->best($product, $item->getBasePrice(), $plan) : null;

        $item->applyPromotion($best['promotion'] ?? null, $best['price'] ?? $item->getBasePrice());
    }

    /**
     * List price per month of a side placed for $months months: per slot for a screen.
     */
    public static function priceFor(ProductSide $side, ?int $slots, int $months = 1): float
    {
        return (float) ($side->getMonthlyPriceFor($months) ?? 0) * ($slots ?? 1);
    }

    /** Slots an item of the side takes: 1..the block for a screen, none for a whole side */
    public static function slotsFor(ProductSide $side, ?int $slots): ?int
    {
        return $side->isAirtime() ? min($side->getSlotCount(), max(1, $slots ?? self::DEFAULT_SLOTS)) : null;
    }

    private static function format(float $price): string
    {
        return number_format($price, 2, '.', '');
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
            $problems[$item->getId()] = $this->bookingManager->availabilityProblem($item->getSide(), $plan->getStartMonth(), $plan->getEndDate(), $item->getSlots());
        }

        return $problems;
    }

    /**
     * Creates a 24h hold for every item that isn't booked yet, in the name of the plan's client.
     * Taken sides are skipped and reported.
     *
     * @return array{booked: int, failed: list<string>}
     */
    public function bookAll(MediaPlan $plan, ?User $by): array
    {
        $client = $plan->getClient();
        if (null === $client) {
            // Bookings are kept per client card, like payments
            return ['booked' => 0, 'failed' => ['Выберите клиента в параметрах медиаплана — бронь закрепляется за карточкой клиента.']];
        }

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
            $request->slots = $item->getSlots();
            $request->client = $client;
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
