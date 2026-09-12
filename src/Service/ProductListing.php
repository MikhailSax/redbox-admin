<?php

namespace App\Service;

use App\Dto\ProductListQuery;
use App\Entity\Product;
use App\Enum\AvailabilityStatus;
use App\Repository\ProductRepository;
use App\Service\Availability\AvailabilityResolver;
use App\Service\Availability\ProductAvailability;
use Symfony\Component\Clock\ClockInterface;

/**
 * Filtered structures with their availability for the chosen month: paginated for the list, all at once for the map.
 */
class ProductListing
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly AvailabilityResolver $availability,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     products: list<Product>,
     *     availability: array<int, ProductAvailability>,
     *     statusCounts: array<string, int>,
     *     total: int,
     *     pages: int,
     *     month: \DateTimeImmutable,
     * }
     */
    public function page(ProductListQuery $query, int $perPage): array
    {
        ['ids' => $ids, 'availability' => $availability, 'statusCounts' => $statusCounts, 'month' => $month] = $this->resolve($query);

        $total = \count($ids);
        $pageIds = \array_slice($ids, ($query->page - 1) * $perPage, $perPage);

        return [
            'products' => $this->products->findForList($pageIds),
            'availability' => array_intersect_key($availability, array_flip($pageIds)),
            'statusCounts' => $statusCounts,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'month' => $month,
        ];
    }

    /**
     * Every matching structure (no pagination), e.g. for the map.
     *
     * @return array{products: list<Product>, availability: array<int, ProductAvailability>, statusCounts: array<string, int>, month: \DateTimeImmutable}
     */
    public function all(ProductListQuery $query): array
    {
        ['ids' => $ids, 'availability' => $availability, 'statusCounts' => $statusCounts, 'month' => $month] = $this->resolve($query);

        return [
            'products' => $this->products->findForList($ids),
            'availability' => $availability,
            'statusCounts' => $statusCounts,
            'month' => $month,
        ];
    }

    /**
     * @return array{ids: list<int>, availability: array<int, ProductAvailability>, statusCounts: array<string, int>, month: \DateTimeImmutable}
     */
    private function resolve(ProductListQuery $query): array
    {
        $month = null !== $query->month && '' !== $query->month
            ? MonthCalendar::parse($query->month)
            : MonthCalendar::firstDay($this->clock->now());

        // Status is computed from bookings, not stored: resolve it for every match (one aggregate query),
        // then count and filter in PHP.
        $ids = $this->products->findMatchingIds($query);
        $availability = $this->availability->forProducts($ids, $month);

        $statusCounts = array_fill_keys(array_map(static fn (AvailabilityStatus $s) => $s->value, AvailabilityStatus::cases()), 0);
        foreach ($availability as $item) {
            if (null !== $status = $item->status()) {
                ++$statusCounts[$status->value];
            }
        }

        $filter = $query->statusFilter();
        if (null !== $filter) {
            $ids = array_values(array_filter($ids, static fn (int $id) => $availability[$id]->status() === $filter));
            $availability = array_intersect_key($availability, array_flip($ids));
        }

        return ['ids' => $ids, 'availability' => $availability, 'statusCounts' => $statusCounts, 'month' => $month];
    }
}
