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
use App\Service\PromotionResolver;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns structures into the JSON the website gets.
 *
 * Only what a visitor may see: no partner names, purchase prices or margins, and for screens only whether
 * they are free: how the block is split into slots and how many are taken stays in the CRM.
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
            // the sides' own types: "Видеоэкран + Статика" when a screen and a static poster share the structure
            'type' => $product->getTypeLabel(),
            'district' => $product->getDistrict()?->getName(),
            'size' => $product->getSize(),
            'sizeLabel' => ProductHelper::sizeLabel($product->getSize()),
            // true when some side is a screen; each side says how it is sold ("airtime" of the side)
            'airtime' => $product->hasAirtimeSides(),
            'minDays' => BookingMode::MIN_DAYS,
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
     * A whole side is busy while booked; a screen only while every slot of its block is taken.
     *
     * @return array<string, mixed>
     */
    public function availability(Product $product, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $now = $this->clock->now();

        $sides = [];
        foreach ($product->getSides() as $side) {
            $active = $this->bookings->findActiveOverlapping($side, $from, $to, $now);
            $busy = $side->isAirtime()
                ? BookingManager::fullPeriods($side, $active, $from, $to)
                : array_map(static fn (Booking $booking) => [max($booking->getStartDate(), $from), min($booking->getEndDate(), $to)], $active);
            $sides[] = [
                'id' => $side->getId(),
                'name' => $side->getName(),
                'type' => $side->getEffectiveProductType()?->getName(),
                'airtime' => $side->isAirtime(),
                'price' => null !== $side->getEffectivePrice() ? (float) $side->getEffectivePrice() : null,
                'busy' => array_map(static fn (array $period) => ['from' => $period[0]->format('Y-m-d'), 'to' => $period[1]->format('Y-m-d')], $busy),
            ];
        }

        return [
            'id' => $product->getId(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'airtime' => $product->hasAirtimeSides(),
            'minDays' => BookingMode::MIN_DAYS,
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
            'priceUnit' => 'perMonth',
            // per month for 1, 3 and 6 months; two weeks as a whole
            'prices' => array_filter([
                'twoWeeks' => self::amount($side->getPrice2Weeks()),
                'month' => self::amount($side->getEffectivePrice()),
                'threeMonths' => self::amount($side->getPrice3Months()),
                'sixMonths' => self::amount($side->getPrice6Months()),
            ], static fn (?float $value) => null !== $value) ?: null,
            'print' => null !== $side->getPrintPrice() ? array_filter(['price' => (float) $side->getPrintPrice(), 'note' => $side->getPrintNote()], static fn (mixed $value) => null !== $value) : null,
            // a screen is free while it has a free slot; the slots themselves are not shown
            'status' => $state?->status()->value,
            'statusLabel' => $state?->status()->label(),
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

    private static function amount(?string $price): ?float
    {
        return null !== $price ? (float) $price : null;
    }
}
