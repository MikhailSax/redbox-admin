<?php

namespace App\Service\Api;

use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\ProductSide;
use App\Entity\Promotion;
use App\Enum\BookingMode;
use App\Helpers\ProductHelper;
use App\Repository\BookingRepository;
use App\Service\Availability\ProductAvailability;
use App\Service\Availability\SideAvailability;
use App\Service\BookingManager;
use App\Service\MediaPlanManager;
use App\Service\PromotionResolver;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns structures into the JSON the website gets.
 *
 * Only what a visitor may see: no partner names, purchase prices or margins.
 */
class CatalogPresenter
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly PromotionResolver $promotions,
        private readonly Packages $assets,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function structure(Product $product, ?ProductAvailability $availability = null, bool $withSides = true): array
    {
        $sides = [];
        foreach ($product->getSides() as $side) {
            $sides[] = $this->side($side, $availability?->sides ?? []);
        }

        return array_filter([
            'id' => $product->getId(),
            'code' => $product->getSchemeNumber(),
            'name' => $product->getName(),
            'category' => $product->getCategory()?->getName(),
            'type' => $product->getProductType()?->getName(),
            'district' => $product->getDistrict()?->getName(),
            'size' => $product->getSize(),
            'sizeLabel' => ProductHelper::sizeLabel($product->getSize()),
            // true when some side is a screen; each side says how it is sold ("airtime" of the side)
            'airtime' => $product->hasAirtimeSides(),
            'loopSeconds' => $product->hasAirtimeSides() ? BookingMode::LOOP_SECONDS : null,
            'clipDurations' => $product->hasAirtimeSides() ? BookingMode::CLIP_DURATIONS : null,
            'priceFrom' => null !== $product->getPrice() ? (float) $product->getPrice() : null,
            'description' => $product->getShortDescription(),
            'lat' => null !== $product->getLatitude() ? (float) $product->getLatitude() : null,
            'lng' => null !== $product->getLongitude() ? (float) $product->getLongitude() : null,
            'status' => $availability?->status()?->value,
            'statusLabel' => $availability?->status()?->label(),
            'photos' => $this->photos($product),
            'promotions' => array_map($this->promotion(...), $this->promotions->publicFor($product)),
            'sides' => $withSides ? $sides : null,
        ], static fn (mixed $value) => null !== $value);
    }

    /**
     * Busy periods of every side between two days, so the site can draw a calendar.
     *
     * @return array<string, mixed>
     */
    public function availability(Product $product, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $now = $this->clock->now();
        $airtime = $product->hasAirtimeSides();

        $sides = [];
        foreach ($product->getSides() as $side) {
            $active = $this->bookings->findActiveOverlapping($side, $from, $to, $now);
            $sides[] = [
                'id' => $side->getId(),
                'name' => $side->getName(),
                'type' => $side->getEffectiveProductType()?->getName(),
                'airtime' => $side->isAirtime(),
                'price' => null !== $side->getEffectivePrice() ? (float) $side->getEffectivePrice() : null,
                // For a screen: seconds of the loop taken on the busiest day of the period
                'busySeconds' => $side->isAirtime() ? BookingManager::peak($active, $from, $to)['used'] : null,
                'busy' => array_map(static fn (Booking $booking) => array_filter([
                    'from' => max($booking->getStartDate(), $from)->format('Y-m-d'),
                    'to' => min($booking->getEndDate(), $to)->format('Y-m-d'),
                    'seconds' => $booking->getClipDuration(),
                ], static fn (mixed $value) => null !== $value), $active),
            ];
        }

        return [
            'id' => $product->getId(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'airtime' => $airtime,
            'loopSeconds' => $airtime ? BookingMode::LOOP_SECONDS : null,
            'sides' => $sides,
        ];
    }

    /**
     * @param list<SideAvailability> $availability
     *
     * @return array<string, mixed>
     */
    private function side(ProductSide $side, array $availability): array
    {
        $state = null;
        foreach ($availability as $candidate) {
            if ($candidate->sideId === $side->getId()) {
                $state = $candidate;
                break;
            }
        }

        return array_filter([
            'id' => $side->getId(),
            'name' => $side->getName(),
            'type' => $side->getEffectiveProductType()?->getName(),
            'airtime' => $side->isAirtime(),
            'price' => null !== $side->getEffectivePrice() ? (float) $side->getEffectivePrice() : null,
            // Price of a 5 s clip is the side price; a 15 s clip costs three times as much
            'priceUnit' => $side->isAirtime() ? 'per5sec' : 'perMonth',
            'status' => $state?->status()->value,
            'statusLabel' => $state?->status()->label(),
            'freeSeconds' => null !== $state && $state->airtime ? $state->freeSeconds() : null,
            // how loaded the screen's loop is this month, 0..100
            'loadPercent' => null !== $state && $state->airtime ? $state->loadPercent() : null,
            'photos' => array_map(fn ($photo) => $this->assets->getUrl($photo->getWebPath()), $side->getPhotos()->toArray()),
        ], static fn (mixed $value) => null !== $value);
    }

    /**
     * @return list<string>
     */
    private function photos(Product $product): array
    {
        $urls = [];
        foreach ($product->getSides() as $side) {
            foreach ($side->getPhotos() as $photo) {
                $urls[] = $this->assets->getUrl($photo->getWebPath());
            }
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>
     */
    private function promotion(Promotion $promotion): array
    {
        return array_filter([
            'title' => $promotion->getTitle(),
            'label' => $promotion->getDiscountLabel(),
            'description' => $promotion->getDescription(),
            'endsAt' => $promotion->getEndsAt()?->format('Y-m-d'),
            'minMonths' => $promotion->getMinMonths(),
        ], static fn (mixed $value) => null !== $value);
    }

    /** Price of a side for a period, at the list price with public promotions applied */
    public function priceFor(ProductSide $side, ?int $clip): float
    {
        return MediaPlanManager::priceFor($side, $clip);
    }
}
