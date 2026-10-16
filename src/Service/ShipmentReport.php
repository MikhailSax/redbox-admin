<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductSideRepository;

/**
 * Shipments of a period: how much the structures are loaded with money, counted by days.
 * A confirmed booking ships its "Продано за" day by day over the days it runs: 90 000 for September–November
 * brings 30 000 to October. Services are one-off and are shipped whole in the month the booking starts.
 * Holds, cancelled and expired bookings ship nothing. By structure, by month and by who made the booking
 * ("who collected how much"); of all sides or of one side letter, of everyone or of one manager.
 */
class ShipmentReport
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ProductRepository $products,
        private readonly ProductSideRepository $sides,
    ) {
    }

    /**
     * @return array{
     *     totals: array{bookings: int, days: int, placement: float, services: float, total: float, paid: float, unpaid: float, unpriced: int, products: int, allProducts: int, sides: int, allSides: int},
     *     byMonth: list<array{month: \DateTimeImmutable, placement: float, services: float, total: float, paid: float}>,
     *     products: list<array{product: Product, sides: list<string>, bookings: int, days: int, placement: float, services: float, total: float, paid: float, unpriced: int, share: float, months: array<string, float>}>,
     *     managers: list<array{user: ?User, bookings: int, placement: float, services: float, total: float, paid: float, average: float}>,
     * }
     *
     * @param list<string> $sideNames
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to, array $sideNames = [], ?int $manager = null): array
    {
        $shipped = $this->bookings->findShipped($from, $to, $sideNames, $manager);

        $months = [];
        foreach (MonthCalendar::between($from, $to) as $month) {
            $months[$month->format('Y-m')] = ['month' => $month, 'placement' => 0.0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0];
        }

        $products = [];
        $managers = [];
        $sides = [];
        foreach ($shipped as $booking) {
            $product = $booking->getProduct();
            $row = &$products[$product->getId()];
            $row ??= ['product' => $product, 'sides' => [], 'bookings' => 0, 'days' => 0, 'placement' => 0.0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'unpriced' => 0, 'share' => 0.0, 'months' => array_fill_keys(array_keys($months), 0.0)];
            $sum = self::sum([$booking], $from, $to);
            ++$row['bookings'];
            $row['days'] += $booking->getDaysWithin($from, $to);
            foreach (['placement', 'services', 'total', 'paid', 'unpriced'] as $key) {
                $row[$key] += $sum[$key];
            }
            $row['sides'][$booking->getSide()->getName()] = $booking->getSide()->getName();
            $sides[$booking->getSide()->getId()] = true;

            foreach ($months as $key => &$month) {
                $part = self::sum([$booking], $month['month'], MonthCalendar::lastDay($month['month']));
                $month['placement'] += $part['placement'];
                $month['services'] += $part['services'];
                $month['total'] += $part['total'];
                $month['paid'] += $part['paid'];
                $row['months'][$key] += $part['total'];
            }
            unset($month, $row);

            $author = $booking->getCreatedBy();
            $row = &$managers[$author?->getId() ?? 0];
            $row ??= ['user' => $author, 'bookings' => 0, 'placement' => 0.0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'average' => 0.0];
            ++$row['bookings'];
            foreach (['placement', 'services', 'total', 'paid'] as $key) {
                $row[$key] += $sum[$key];
            }
            unset($row);
        }
        foreach ($managers as &$row) {
            $row['average'] = $row['bookings'] > 0 ? $row['total'] / $row['bookings'] : 0.0;
        }
        unset($row);

        $totals = ['bookings' => 0, 'days' => 0, 'placement' => 0.0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'unpriced' => 0];
        foreach ($products as $row) {
            foreach (['bookings', 'days', 'placement', 'services', 'total', 'paid', 'unpriced'] as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $totals['unpaid'] = $totals['total'] - $totals['paid'];
        // placed not for money: how many of each kind, and the certificates' sums by the days of the period
        $kinds = SalesReport::kinds($shipped);
        $totals['kinds'] = $kinds['kinds'];
        $totals['certificates'] = self::sum($shipped, $from, $to)['certificates'];

        foreach ($products as &$row) {
            $row['sides'] = array_values($row['sides']);
            sort($row['sides']);
            $row['share'] = $totals['total'] > 0 ? $row['total'] * 100 / $totals['total'] : 0.0;
        }
        unset($row);

        // the most loaded first; managers by what they collected, the ones without a name at the end
        usort($products, static fn (array $a, array $b) => [$b['total'], $b['days']] <=> [$a['total'], $a['days']]);
        usort($managers, static fn (array $a, array $b) => [null === $a['user'], $b['total'], $b['bookings']] <=> [null === $b['user'], $a['total'], $a['bookings']]);

        return [
            'totals' => $totals + [
                'products' => \count($products),
                'allProducts' => $this->products->count([]),
                'sides' => \count($sides),
                'allSides' => $this->sides->countNamed($sideNames),
            ],
            'byMonth' => array_values($months),
            'products' => $products,
            'managers' => array_values($managers),
        ];
    }

    /**
     * What $bookings ship within [$from, $to]: the placement by days, the services of those starting in it,
     * the paid part of it and the part still awaiting payment.
     *
     * Without a period every booking ships whole.
     *
     * @param iterable<Booking> $bookings
     *
     * Certificates' sums (by days, like the price) and free bookings are counted apart from the money.
     *
     * @return array{placement: float, services: float, total: float, paid: float, unpaid: float, unpriced: int, certificates: float, free: int}
     */
    public static function sum(iterable $bookings, ?\DateTimeInterface $periodFrom = null, ?\DateTimeInterface $periodTo = null): array
    {
        $sum = ['placement' => 0.0, 'services' => 0.0, 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'unpriced' => 0, 'certificates' => 0.0, 'free' => 0];
        foreach ($bookings as $booking) {
            $from = $periodFrom ?? $booking->getStartDate();
            $to = $periodTo ?? $booking->getEndDate();
            if (0 === $booking->getDaysWithin($from, $to)) {
                continue;
            }
            $placement = $booking->getSoldPriceWithin($from, $to);
            $services = $booking->getServicesWithin($from, $to);
            $sum['unpriced'] += null === $placement ? 1 : 0;
            $sum['placement'] += $placement ?? 0;
            $sum['services'] += $services;
            $sum[$booking->isPaid() ? 'paid' : 'unpaid'] += ($placement ?? 0) + $services;
            $sum['certificates'] += $booking->getCertificateWithin($from, $to);
            $sum['free'] += $booking->getKind()->isFree() ? 1 : 0;
        }
        $sum['total'] = $sum['placement'] + $sum['services'];

        // kopecks summed as floats: round them back
        return array_map(static fn (int|float $value) => \is_float($value) ? round($value, 2) : $value, $sum);
    }
}
