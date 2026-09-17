<?php

namespace App\Controller\Api;

use App\Dto\ProductListQuery;
use App\Entity\Product;
use App\Helpers\ProductHelper;
use App\Repository\CategoryRepository;
use App\Repository\DistrictRepository;
use App\Repository\ProductTypeRepository;
use App\Service\Api\CatalogPresenter;
use App\Service\ProductListing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Public catalogue for the website: structures, their availability and the values for filters.
 */
#[Route('/api/v1', name: 'api_')]
final class CatalogController extends AbstractController
{
    private const PER_PAGE = 24;

    /** How far ahead a calendar may be asked for */
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly ProductListing $listing,
        private readonly CatalogPresenter $presenter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Structures with filters and paging: /api/v1/structures?q=&category=&type=&district=&size=&status=free&month=2026-10&page=1
     */
    #[Route('/structures', name: 'structures', methods: ['GET'])]
    public function structures(#[MapQueryString] ProductListQuery $query = new ProductListQuery()): JsonResponse
    {
        ['products' => $products, 'availability' => $availability, 'statusCounts' => $counts, 'total' => $total, 'pages' => $pages, 'month' => $month]
            = $this->listing->page($query, self::PER_PAGE);

        return $this->json([
            'items' => array_map(fn (Product $product) => $this->presenter->structure($product, $availability[$product->getId()] ?? null), $products),
            'page' => $query->page,
            'pages' => $pages,
            'total' => $total,
            'month' => $month->format('Y-m'),
            'statusCounts' => $counts,
        ]);
    }

    #[Route('/structures/{id}', name: 'structure', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function structure(Product $product, #[MapQueryParameter] ?string $month = null): JsonResponse
    {
        $availability = $this->listing->all(new ProductListQuery(month: $month))['availability'][$product->getId()] ?? null;

        return $this->json($this->presenter->structure($product, $availability));
    }

    /**
     * Busy days of every side: /api/v1/structures/12/availability?from=2026-10-01&to=2026-12-31
     */
    #[Route('/structures/{id}/availability', name: 'structure_availability', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function availability(Product $product, #[MapQueryParameter] ?string $from = null, #[MapQueryParameter] ?string $to = null): JsonResponse
    {
        $today = $this->clock->now()->setTime(0, 0);
        $start = self::date($from) ?? $today;
        $end = self::date($to) ?? $start->modify('+90 days');

        if ($end < $start) {
            return $this->json(['error' => 'invalid_period', 'message' => 'Дата «по» раньше даты «с»'], 400);
        }
        if ($start->diff($end)->days > self::MAX_DAYS) {
            $end = $start->modify(\sprintf('+%d days', self::MAX_DAYS));
        }

        return $this->json($this->presenter->availability($product, $start, $end));
    }

    /**
     * Values for the catalogue filters.
     */
    #[Route('/filters', name: 'filters', methods: ['GET'])]
    public function filters(CategoryRepository $categories, ProductTypeRepository $types, DistrictRepository $districts): JsonResponse
    {
        $named = static fn (array $rows) => array_map(static fn (object $row) => ['id' => $row->getId(), 'name' => $row->getName()], $rows);

        return $this->json([
            'categories' => $named($categories->findBy([], ['name' => 'ASC'])),
            'types' => $named($types->findBy([], ['name' => 'ASC'])),
            'districts' => $named($districts->findBy([], ['name' => 'ASC'])),
            'sizes' => array_map(static fn (string $key) => ['id' => $key, 'name' => ProductHelper::SIZES[$key]], ProductHelper::sizeKeys()),
            'statuses' => [
                ['id' => 'free', 'name' => 'Свободна'],
                ['id' => 'booked', 'name' => 'Забронирована'],
                ['id' => 'occupied', 'name' => 'Занята'],
            ],
        ]);
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date ? $date : null;
    }
}
