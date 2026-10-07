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
 * $workingOnly leaves out sides out of order, and structures with none working (what is for sale: the website, media plans, the map).
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
    public function page(ProductListQuery $query, int $perPage, bool $workingOnly = false): array
    {
        ['ids' => $ids, 'availability' => $availability, 'statusCounts' => $statusCounts, 'month' => $month] = $this->resolve($query, $workingOnly);

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
    public function all(ProductListQuery $query, bool $workingOnly = false): array
    {
        ['ids' => $ids, 'availability' => $availability, 'statusCounts' => $statusCounts, 'month' => $month] = $this->resolve($query, $workingOnly);

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
    private function resolve(ProductListQuery $query, bool $workingOnly): array
    {
        $month = null !== $query->month && '' !== $query->month
            ? MonthCalendar::parse($query->month)
            : MonthCalendar::firstDay($this->clock->now());

        // Status is computed from bookings, not stored: resolve it for every match (one aggregate query),
        // then count and filter in PHP.
        $ids = $this->products->findMatchingIds($query, $workingOnly);
        $availability = $this->availability->forProducts($ids, $month, $workingOnly);

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

        // Free first (or last), then booked, then occupied; structures without a status at the end
        if ('status' === $query->sort) {
            $rank = array_flip(array_map(static fn (AvailabilityStatus $s) => $s->value, AvailabilityStatus::cases()));
            $position = array_flip($ids);
            $sign = $query->isDescending() ? -1 : 1;
            usort($ids, static function (int $a, int $b) use ($availability, $rank, $position, $sign): int {
                $ra = $rank[$availability[$a]->status()?->value] ?? null;
                $rb = $rank[$availability[$b]->status()?->value] ?? null;
                if ($ra === $rb) {
                    return $position[$a] <=> $position[$b];
                }

                return null === $ra ? 1 : (null === $rb ? -1 : $sign * ($ra <=> $rb));
            });
        }

        return ['ids' => $ids, 'availability' => $availability, 'statusCounts' => $statusCounts, 'month' => $month];
    }
}
