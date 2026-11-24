<?php

namespace App\Controller\Admin;

use App\Dto\ReportQuery;
use App\Entity\User;
use App\Enum\ClientType;
use App\Repository\BookingRepository;
use App\Service\CashFlowReport;
use App\Service\DebtReport;
use App\Service\MonthCalendar;
use App\Service\ReportExport;
use App\Service\SalesReport;
use App\Service\ShipmentReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reports for managers: what sold in a period, by structure and by who made the booking; what was shipped in it —
 * the bookings' sums by the days they run; what clients owe today (receivables, by how late); and the money planned
 * against the money received, month by month. Each one downloads as an Excel file.
 */
#[IsGranted(User::ROLE_SUPER_MANAGER)]
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly SalesReport $sales,
        private readonly ShipmentReport $shipments,
        private readonly DebtReport $debts,
        private readonly CashFlowReport $cashFlow,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/reports', name: 'admin_report_index', methods: ['GET'])]
    public function index(Request $request, BookingRepository $bookings, #[MapQueryString] ReportQuery $query = new ReportQuery()): Response
    {
        $now = $this->clock->now();
        $month = MonthCalendar::firstDay($now);
        $quarter = $month->modify(\sprintf('-%d months', ((int) $month->format('n') - 1) % 3));

        $params = $this->report($query) + [
            'query' => $query,
            // who made bookings, for the manager filter
            'authors' => $bookings->findAuthors(),
            'clientTypes' => ClientType::cases(),
            'now' => $now,
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

    /**
     * The report on screen as an .xlsx file: the same period, filters and sorting.
     */
    #[Route('/admin/reports/export', name: 'admin_report_export', methods: ['GET'])]
    public function export(ReportExport $export, #[MapQueryString] ReportQuery $query = new ReportQuery()): Response
    {
        $report = $this->report($query);
        $file = $export->write($report);
        $name = $export->filename($report);

        $response = new Response((string) file_get_contents($file), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $export->asciiFilename($report)),
        ]);
        @unlink($file);

        return $response;
    }

    /**
     * The numbers of the chosen report, with its view, period and query.
     *
     * @return array<string, mixed>
     */
    private function report(ReportQuery $query): array
    {
        $now = $this->clock->now();
        [$from, $to] = $query->period($now);
        $view = $query->viewName();

        $report = match ($view) {
            ReportQuery::DEBTS => $this->debts->build($now, $query->clientType()),
            ReportQuery::CASHFLOW => $this->cashFlow->build($from, $to, $now),
            ReportQuery::SHIPMENTS => $this->shipments->build($from, $to, $query->sideNames(), $query->manager),
            default => $this->sales->build($from, $to, $query->sideNames(), $query->manager),
        };
        if (isset($report['products'])) {
            $report['products'] = $query->sortProducts($report['products']);
        }
        if (isset($report['managers'])) {
            $report['managers'] = $query->sortManagers($report['managers']);
        }

        return $report + ['view' => $view, 'from' => $from, 'to' => $to, 'query' => $query, 'now' => $now];
    }
}
