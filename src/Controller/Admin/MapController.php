<?php

namespace App\Controller\Admin;

use App\Dto\ProductListQuery;
use App\Entity\Product;
use App\Entity\Promotion;
use App\Repository\CategoryRepository;
use App\Repository\MediaPlanRepository;
use App\Repository\PartnerRepository;
use App\Repository\ProductTypeRepository;
use App\Service\Availability\SideAvailability;
use App\Service\MonthCalendar;
use App\Service\ProductListing;
use App\Service\PromotionResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Map of all structures (assets/admin/map.js), filtered like the list and coloured by status.
 */
final class MapController extends AbstractController
{
    #[Route('/admin/map', name: 'admin_map', methods: ['GET'])]
    public function index(
        CategoryRepository $categories,
        ProductTypeRepository $types,
        PartnerRepository $partners,
        MediaPlanRepository $plans,
        ClockInterface $clock,
        #[MapQueryString] ProductListQuery $query = new ProductListQuery(),
        #[MapQueryParameter] ?int $plan = null,
    ): Response {
        return $this->render('admin/map/index.html.twig', [
            'query' => $query,
            'months' => MonthCalendar::range($clock->now(), 12),
            'categories' => $categories->findBy([], ['name' => 'ASC']),
            'types' => $types->findBy([], ['name' => 'ASC']),
            'partners' => $partners->findBy([], ['name' => 'ASC']),
            // "Add to media plan" buttons in the popups target the selected plan
            'plans' => $plans->findRecent(20),
            'selectedPlan' => $plan,
        ]);
    }

    #[Route('/admin/map/data', name: 'admin_map_data', methods: ['GET'])]
    public function data(ProductListing $listing, Packages $assets, PromotionResolver $promotions, #[MapQueryString] ProductListQuery $query = new ProductListQuery()): JsonResponse
    {
        ['products' => $products, 'availability' => $availability, 'statusCounts' => $statusCounts, 'month' => $month] = $listing->all($query);

        $points = [];
        $withoutCoordinates = 0;
        foreach ($products as $product) {
            if (!self::isMappable($product)) {
                ++$withoutCoordinates;
                continue;
            }
            $item = $availability[$product->getId()];
            $points[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'lat' => (float) $product->getLatitude(),
                'lng' => (float) $product->getLongitude(),
                'status' => $item->status()?->value,
                'statusLabel' => $item->status()?->label(),
                'sides' => array_map(static fn (SideAvailability $side) => [
                    'id' => $side->sideId,
                    'name' => $side->sideName,
                    'status' => $side->status()->value,
                    'label' => $side->status()->label(),
                    'airtime' => $side->airtime,
                    'used' => $side->usedSlots(),
                    'slots' => $side->slotCount,
                ], $item->sides),
                'meta' => implode(' · ', array_filter([$product->getCategory()?->getName(), $product->getTypeLabel(), $product->getDistrict()?->getName()])),
                'owner' => $product->getOwner()?->getName(),
                'price' => null !== $product->getPrice() ? (float) $product->getPrice() : null,
                // only what any client gets; conditional promotions are on the structure card
                'promotions' => array_map(static fn (Promotion $p) => ['label' => $p->getDiscountLabel(), 'title' => $p->getTitle()], $promotions->publicFor($product)),
                'cover' => ($cover = $product->getCoverPhoto()) ? $assets->getUrl($cover->getWebPath()) : null,
                'urls' => [
                    'edit' => $this->generateUrl('admin_product_edit', ['id' => $product->getId()]),
                    'booking' => $this->generateUrl('admin_booking_product', ['id' => $product->getId()]),
                ],
            ];
        }

        return $this->json([
            'month' => MonthCalendar::label($month),
            'statusCounts' => $statusCounts,
            'points' => $points,
            'withoutCoordinates' => $withoutCoordinates,
        ]);
    }

    /**
     * Structures created before coordinates were required have none and can't be placed on the map.
     */
    private static function isMappable(Product $product): bool
    {
        return null !== $product->getLatitude() && null !== $product->getLongitude();
    }
}
