<?php

namespace App\Controller\Admin;

use App\Dto\ReportQuery;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Service\MonthCalendar;
use App\Service\SalesReport;
use App\Service\ShipmentReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reports for managers: what sold in a period, by structure and by who made the booking;
 * and what was shipped in it — the bookings' sums by the days they run, by structure and by month.
 */
#[IsGranted(User::ROLE_SUPER_MANAGER)]
final class ReportController extends AbstractController
{
    #[Route('/admin/reports', name: 'admin_report_index', methods: ['GET'])]
    public function index(Request $request, SalesReport $sales, ShipmentReport $shipments, BookingRepository $bookings, ClockInterface $clock, #[MapQueryString] ReportQuery $query = new ReportQuery()): Response
    {
        $now = $clock->now();
        [$from, $to] = $query->period($now);
        $month = MonthCalendar::firstDay($now);
        $quarter = $month->modify(\sprintf('-%d months', ((int) $month->format('n') - 1) % 3));

        $report = $query->isShipments()
            ? $shipments->build($from, $to, $query->sideNames(), $query->manager)
            : $sales->build($from, $to, $query->sideNames(), $query->manager);
        $report['products'] = $query->sortProducts($report['products']);
        $report['managers'] = $query->sortManagers($report['managers']);

        $params = $report + [
            'query' => $query,
            // who made bookings, for the manager filter
            'authors' => $bookings->findAuthors(),
            'view' => $query->isShipments() ? ReportQuery::SHIPMENTS : ReportQuery::SALES,
            'from' => $from,
            'to' => $to,
            // two years back for the period pickers
            'months' => array_reverse(MonthCalendar::range($month->modify('-23 months'), 24)),
            'presets' => [
                'Этот месяц' => [$month, $month],
                'Прошлый месяц' => [$month->modify('-1 month'), $month->modify('-1 month')],
                'Этот квартал' => [$quarter, $month],
                'С начала года' => [$month->modify(\sprintf('-%d months', (int) $month->format('n') - 1)), $month],
                '12 месяцев' => [$month->modify('-11 months'), $month],
            ],
        ];

        // Another period picked (assets/admin/live-filter.js) only needs the results block
        if ($request->headers->has('X-Live-Filter')) {
            return $this->renderBlock('admin/report/index.html.twig', 'results', $params);
        }

        return $this->render('admin/report/index.html.twig', $params);
    }
}
