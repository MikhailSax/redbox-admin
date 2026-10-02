<?php

namespace App\Service;

use App\Dto\BookingRequest;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\ProductSide;
use App\Entity\User;
use App\Enum\BookingStatus;
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
    public function addSide(MediaPlan $plan, ProductSide $side, ?int $slots = null, ?int $slotSeconds = null): ?MediaPlanItem
    {
        // a side out of order is not offered
        if ($plan->hasSide($side) || !$side->isWorking()) {
            return null;
        }

        $slots = self::slotsFor($side, $slots);
        $item = new MediaPlanItem($side, self::format(self::priceFor($side, $slots, $plan->getMonths(), $slotSeconds)), $slots);
        $item->setSlotSeconds($slotSeconds);
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
            $item->setBasePrice(self::format(self::priceFor($item->getSide(), $item->getSlots(), $plan->getMonths(), $item->getSlotSeconds())));
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
        $item->setBasePrice(self::format(self::priceFor($item->getSide(), $item->getSlots(), (int) $item->getPlan()?->getMonths(), $item->getSlotSeconds())));
        $this->applyBestPromotion($item);
        $item->getPlan()?->touch();
    }

    /**
     * Changes the slots of a screen item (and the seconds of each: the whole slot or 5 of 10);
     * the price follows unless the manager typed it.
     */
    public function changeSlots(MediaPlanItem $item, int $slots, ?int $slotSeconds = null): void
    {
        $item->setSlots(self::slotsFor($item->getSide(), $slots));
        $item->setSlotSeconds($slotSeconds);
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
     * List price per month of a side placed for $months months: per slot for a screen,
     * half of it for 5 seconds of a 10-second slot.
     */
    public static function priceFor(ProductSide $side, ?int $slots, int $months = 1, ?int $slotSeconds = null): float
    {
        $share = null !== $slots && $side->isAirtime() ? $side->secondsPerSlot($slotSeconds) / max(1, $side->getSlotSeconds()) : 1;

        return (float) ($side->getMonthlyPriceFor($months) ?? 0) * ($slots ?? 1) * $share;
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
            $problems[$item->getId()] = $this->bookingManager->availabilityProblem($item->getSide(), $plan->getStartMonth(), $plan->getEndDate(), $item->getSlots(), $item->getSlotSeconds());
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

            try {
                $item->setBooking($this->bookingManager->hold($this->bookingRequest($plan, $item, $client), $by));
                ++$booked;
            } catch (BookingException $e) {
                $failed[] = \sprintf('%s: %s', $item->getProduct()?->getName(), $e->getMessage());
            }
        }

        $plan->touch();
        $this->entityManager->flush();

        return ['booked' => $booked, 'failed' => $failed];
    }

    /**
     * The sides stop waiting: every hold of the plan becomes a confirmed booking (paid or not — post-paying clients
     * pay later). An item whose hold has expired — or that was never booked — is booked again and confirmed at once;
     * a side taken meanwhile is reported. With $markPaid (the plan is paid in full) the bookings are marked paid too.
     * Called when a payment of the plan is marked paid, and by the "Подтвердить брони" button on the plan.
     *
     * @return array{confirmed: int, created: int, failed: list<string>}
     */
    public function confirmBookings(MediaPlan $plan, ?User $by, bool $markPaid = false): array
    {
        $client = $plan->getClient();
        if (null === $client) {
            return ['confirmed' => 0, 'created' => 0, 'failed' => ['Выберите клиента в параметрах медиаплана — бронь закрепляется за карточкой клиента.']];
        }

        $now = $this->clock->now();
        $confirmed = 0;
        $created = 0;
        $failed = [];

        foreach ($plan->getItems() as $item) {
            $booking = $item->getBooking();
            if (BookingStatus::Confirmed === $booking?->getStatus()) {
                if ($markPaid && !$booking->isPaid()) {
                    $this->bookingManager->markPaid($booking);
                }
                continue;
            }

            try {
                if (null !== $booking && BookingStatus::Hold === $booking->getStatus() && !$booking->isHoldOverdue($now)) {
                    $markPaid ? $this->bookingManager->markPaid($booking) : $this->bookingManager->confirm($booking);
                    ++$confirmed;
                    continue;
                }

                $fresh = $this->bookingManager->hold($this->bookingRequest($plan, $item, $client), $by);
                $markPaid ? $this->bookingManager->markPaid($fresh) : $this->bookingManager->confirm($fresh);
                $item->setBooking($fresh);
                ++$created;
            } catch (BookingException $e) {
                $failed[] = \sprintf('%s: %s', $item->getProduct()?->getName(), $e->getMessage());
            }
        }

        $plan->touch();
        $this->entityManager->flush();

        return ['confirmed' => $confirmed, 'created' => $created, 'failed' => $failed];
    }

    /** One item of the plan as a booking request: the plan's months, client and contacts */
    private function bookingRequest(MediaPlan $plan, MediaPlanItem $item, User $client): BookingRequest
    {
        $request = new BookingRequest();
        $request->side = $item->getSide();
        $request->startMonth = $plan->getStartMonth()->format('Y-m');
        $request->months = $plan->getMonths();
        $request->slots = $item->getSlots();
        $request->slotSeconds = $item->getSlotSeconds();
        $request->client = $client;
        $request->clientName = (string) $plan->getClientName();
        $request->clientPhone = $plan->getClientContact() ?: '—';
        $request->comment = \sprintf('Медиаплан «%s»', $plan->getTitle());

        return $request;
    }
}
