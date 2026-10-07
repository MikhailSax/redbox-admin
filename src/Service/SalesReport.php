<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sales of a period: a sale is a booking confirmed (or paid) within it and still confirmed; it counts whole
 * — its "Продано за" and its services — in the month it was confirmed, whatever months it runs.
 * By structure (what sold, how many sides, months and days of airtime) and by who made the booking.
 */
class SalesReport
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ProductRepository $products,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     totals: array{sales: int, placement: int, services: float, total: float, paid: float, unpriced: int, average: float, products: int, allProducts: int},
     *     products: list<array{product: Product, sales: int, sides: list<string>, months: int, airtimeDays: int, placement: int, services: float, total: float, paid: float, unpriced: int, share: float}>,
     *     managers: list<array{user: ?User, sales: int, placement: int, services: float, total: float, paid: float, average: float, burned: int}>,
     *     idle: list<Product>,
     * }
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $sold = $this->bookings->findSold($from, $to);

        $products = [];
        $managers = [];
        foreach ($sold as $booking) {
            $product = $booking->getProduct();
            $row = &$products[$product->getId()];
            $row ??= ['product' => $product, 'sales' => 0, 'sides' => [], 'months' => 0, 'airtimeDays' => 0, 'placement' => 0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'unpriced' => 0, 'share' => 0.0];
            self::add($row, $booking);
            $row['sides'][$booking->getSide()->getName()] = $booking->getSide()->getName();
            if ($booking->getSide()->isAirtime()) {
                $row['airtimeDays'] += $booking->getDays();
            } else {
                $row['months'] += $booking->getMonthCount();
            }
            unset($row);

            $author = $booking->getCreatedBy();
            $row = &$managers[$author?->getId() ?? 0];
            $row ??= ['user' => $author, 'sales' => 0, 'placement' => 0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'unpriced' => 0, 'average' => 0.0, 'burned' => 0];
            self::add($row, $booking);
            unset($row);
        }

        // Holds that ran out: the author's lost sales of the period
        foreach ($this->bookings->countBurnedHoldsByAuthor($from, $to, $this->clock->now()) as $authorId => $burned) {
            if (!isset($managers[$authorId])) {
                $user = 0 !== $authorId ? $this->users->find($authorId) : null;
                $managers[$authorId] = ['user' => $user, 'sales' => 0, 'placement' => 0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'unpriced' => 0, 'average' => 0.0, 'burned' => 0];
            }
            $managers[$authorId]['burned'] = $burned;
        }

        $totals = ['sales' => 0, 'placement' => 0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'unpriced' => 0];
        foreach ($products as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        foreach ($products as &$row) {
            $row['sides'] = array_values($row['sides']);
            sort($row['sides']);
            $row['share'] = $totals['total'] > 0 ? $row['total'] * 100 / $totals['total'] : 0.0;
        }
        unset($row);
        foreach ($managers as &$row) {
            $row['average'] = $row['sales'] > 0 ? $row['total'] / $row['sales'] : 0.0;
        }
        unset($row);

        // the best sellers first; managers by what they sold, the ones without a name at the end
        usort($products, static fn (array $a, array $b) => [$b['total'], $b['sales']] <=> [$a['total'], $a['sales']]);
        usort($managers, static fn (array $a, array $b) => [null === $a['user'], $b['total'], $b['sales']] <=> [null === $b['user'], $a['total'], $a['sales']]);

        $all = $this->products->findBy([], ['name' => 'ASC']);
        $soldIds = array_map(static fn (array $row) => $row['product']->getId(), $products);
        $idle = array_values(array_filter($all, static fn (Product $p) => !\in_array($p->getId(), $soldIds, true)));

        return [
            'totals' => $totals + [
                'average' => $totals['sales'] > 0 ? $totals['total'] / $totals['sales'] : 0.0,
                'products' => \count($products),
                'allProducts' => \count($all),
            ],
            'products' => array_values($products),
            'managers' => array_values($managers),
            'idle' => $idle,
        ];
    }

    /** @param array{sales: int, placement: int, services: float, total: float, paid: float, unpriced: int} $row */
    private static function add(array &$row, Booking $booking): void
    {
        $price = $booking->getSoldPrice();
        $services = $booking->getServicesTotal();
        ++$row['sales'];
        $row['placement'] += $price ?? 0;
        $row['services'] += $services;
        $row['total'] += ($price ?? 0) + $services;
        $row['paid'] += $booking->isPaid() ? ($price ?? 0) + $services : 0;
        $row['unpriced'] += null === $price ? 1 : 0;
    }
}
