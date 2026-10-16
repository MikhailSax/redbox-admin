<?php

namespace App\Dto;

use App\Service\MonthCalendar;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Period of the reports, bound from the query string: months "YYYY-MM" from and to, inclusive.
 * Either left out — the current month. $view picks the report: sales (by the month of confirmation)
 * or shipments (by the days the bookings run).
 */
final readonly class ReportQuery
{
    public const SALES = 'sales';
    public const SHIPMENTS = 'shipments';
    public const VIEWS = [self::SALES, self::SHIPMENTS];

    public const PRODUCT_SORTS = ['name', 'sales', 'term', 'bookings', 'days', 'placement', 'services', 'total', 'paid'];
    public const MANAGER_SORTS = ['name', 'sales', 'bookings', 'total', 'paid', 'average', 'burned'];

    public function __construct(
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/')]
        public ?string $from = null,
        #[Assert\Regex('/^\d{4}-(0[1-9]|1[0-2])$/')]
        public ?string $to = null,
        #[Assert\Choice(choices: self::VIEWS)]
        public ?string $view = null,
        /** Side letter, "А" or "В": only the bookings of those sides count */
        #[Assert\Choice(choices: ProductListQuery::SIDES)]
        public ?string $side = null,
        /** Id of the user who made the bookings: only theirs count */
        #[Assert\Positive]
        public ?int $manager = null,
        /** Column the structures table is sorted by, one of PRODUCT_SORTS; the report's own order without one */
        #[Assert\Choice(choices: self::PRODUCT_SORTS)]
        public ?string $sort = null,
        #[Assert\Choice(choices: ['asc', 'desc'])]
        public ?string $dir = null,
        /** Column the managers table is sorted by, one of MANAGER_SORTS */
        #[Assert\Choice(choices: self::MANAGER_SORTS)]
        public ?string $msort = null,
        #[Assert\Choice(choices: ['asc', 'desc'])]
        public ?string $mdir = null,
    ) {
    }

    /** @return list<string> side names of the chosen letter; empty without the filter */
    public function sideNames(): array
    {
        return ProductListQuery::sideSpellings($this->side);
    }

    /**
     * Rows of the structures table in the chosen order.
     *
     * @template T of array
     *
     * @param list<T> $rows
     *
     * @return list<T>
     */
    public function sortProducts(array $rows): array
    {
        return self::sortRows($rows, $this->sort, $this->dir, static fn (array $row) => $row['product']->getName());
    }

    /**
     * Rows of the managers table in the chosen order.
     *
     * @template T of array
     *
     * @param list<T> $rows
     *
     * @return list<T>
     */
    public function sortManagers(array $rows): array
    {
        return self::sortRows($rows, $this->msort, $this->mdir, static fn (array $row) => null !== $row['user'] ? ($row['user']->getName() ?: $row['user']->getEmail()) : null);
    }

    /**
     * By a number column the biggest come first unless asked otherwise, by the name A to Z;
     * "term" is months of whole sides, then days of airtime. Ties keep the report's order.
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): ?string $name
     *
     * @return list<array<string, mixed>>
     */
    private static function sortRows(array $rows, ?string $key, ?string $dir, callable $name): array
    {
        if (null === $key || '' === $key) {
            return $rows;
        }
        $descending = 'name' === $key ? 'desc' === $dir : 'asc' !== $dir;
        $value = static fn (array $row) => match ($key) {
            // rows without a name (no manager) go last either way
            'name' => [null === $name($row), mb_strtolower((string) $name($row))],
            'term' => [$row['months'] ?? 0, $row['airtimeDays'] ?? 0],
            default => $row[$key] ?? 0,
        };
        // usort() is stable: ties keep the report's order
        usort($rows, static function (array $a, array $b) use ($value, $descending, $key) {
            [$x, $y] = [$value($a), $value($b)];
            if ('name' === $key && $x[0] !== $y[0]) {
                return $x[0] <=> $y[0]; // no name: last
            }

            return $descending ? $y <=> $x : $x <=> $y;
        });

        return array_values($rows);
    }

    public function isShipments(): bool
    {
        return self::SHIPMENTS === $this->view;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the first day of the first month, the last day of the last; turned around when given backwards
     */
    public function period(\DateTimeImmutable $now): array
    {
        $from = null !== $this->from && '' !== $this->from ? MonthCalendar::parse($this->from) : MonthCalendar::firstDay($now);
        $to = null !== $this->to && '' !== $this->to ? MonthCalendar::parse($this->to) : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, MonthCalendar::lastDay($to)];
    }
}
