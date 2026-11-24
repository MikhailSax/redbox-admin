<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Payment;
use App\Entity\User;
use App\Enum\ClientType;
use App\Service\DebtReport;
use App\Service\FinanceKpi;
use App\Twig\AdminExtension;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Money reports: receivables (who owes what and how late), payments planned against received by month,
 * the Excel download of every report, and the money tiles of the dashboard.
 */
final class FinanceReportTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    private User $vector;
    private User $petr;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-11-24 12:00:00');

        $this->vector = $this->createClientCard('ООО «Вектор»', 'vector@example.com')->setClientType(ClientType::Legal);
        $this->petr = $this->createUser('petr@example.com', User::ROLE_CLIENT)->setName('Пётр Иванов')->setClientType(ClientType::Individual);
        $this->em->flush();
    }

    public function testAgeBuckets(): void
    {
        self::assertSame('d30', DebtReport::bucket(1));
        self::assertSame('d30', DebtReport::bucket(30));
        self::assertSame('d60', DebtReport::bucket(31));
        self::assertSame('d90', DebtReport::bucket(90));
        self::assertSame('older', DebtReport::bucket(91));
    }

    public function testReceivablesByClientAndHowLate(): void
    {
        $this->payment($this->vector, 'Сентябрь', '30000', '2026-09-01');                 // 84 days late
        $this->payment($this->vector, 'Октябрь', '20000', '2026-11-10');                  // 14 days late
        $this->payment($this->vector, 'Декабрь', '25000', '2026-12-01');                  // not due yet
        $this->payment($this->petr, 'Баннер', '4500', '2026-07-01');                      // 146 days late
        $this->payment($this->petr, 'Дизайн', '9000', '2026-10-01', paid: '2026-10-02');  // paid: not a debt
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/reports?view=debts');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-report-view="debts"][aria-current="page"]');
        // no period for a snapshot of today, a client type instead
        self::assertSelectorNotExists('select[name="from"]');
        self::assertSelectorExists('select[name="type"]');

        self::assertSelectorTextContains('[data-debt-total="debt"]', AdminExtension::money(79500));
        self::assertSelectorTextContains('[data-debt-total="overdue"]', AdminExtension::money(54500));
        self::assertSelectorTextContains('[data-debt-total="current"]', AdminExtension::money(25000));
        self::assertSelectorTextContains('[data-debt-bucket="d30"]', AdminExtension::money(20000));
        self::assertSelectorTextContains('[data-debt-bucket="d90"]', AdminExtension::money(30000));
        self::assertSelectorTextContains('[data-debt-bucket="older"]', AdminExtension::money(4500));

        // the most overdue first
        self::assertSame(
            [(string) $this->vector->getId(), (string) $this->petr->getId()],
            $crawler->filter('#debt-clients [data-debt-client]')->each(static fn ($tr) => $tr->attr('data-debt-client')),
        );
        self::assertStringContainsString('84 дня', $crawler->filter('[data-debt-client="'.$this->vector->getId().'"]')->text());
        self::assertStringContainsString('следующий до 01.12.2026', $crawler->filter('[data-debt-client="'.$this->vector->getId().'"]')->text());
        // the oldest overdue payment on top
        self::assertStringContainsString('Баннер', $crawler->filter('#debt-overdue tbody tr')->first()->text());

        // companies only
        $crawler = $this->client->request('GET', '/admin/reports?view=debts&type=legal');
        self::assertCount(1, $crawler->filter('#debt-clients [data-debt-client]'));
        self::assertSelectorTextContains('[data-debt-total="debt"]', AdminExtension::money(75000));
    }

    public function testPaymentsPlannedAgainstReceived(): void
    {
        $this->payment($this->vector, 'Сентябрь', '30000', '2026-09-05', paid: '2026-09-04');   // on time
        $this->payment($this->vector, 'Октябрь', '20000', '2026-10-05', paid: '2026-11-02');    // a debt paid in November
        $this->payment($this->petr, 'Октябрь', '5000', '2026-10-20');                           // still owed
        $this->payment($this->vector, 'Ноябрь', '20000', '2026-11-05', paid: '2026-11-05');
        $this->payment($this->vector, 'Декабрь', '20000', '2026-12-05');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/reports?view=cashflow&from=2026-09&to=2026-12');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="manager"]');
        self::assertSame(['2026-09', '2026-10', '2026-11', '2026-12'], $crawler->filter('[data-cashflow-month]')->each(static fn ($tr) => $tr->attr('data-cashflow-month')));

        $october = $crawler->filter('[data-cashflow-month="2026-10"]')->text();
        self::assertStringContainsString(AdminExtension::money(25000), $october);           // planned
        // Vector paid October late, in November: of October's plan only Пётр's 5 000 is still owed
        self::assertStringContainsString('просрочено '.AdminExtension::money(5000), $october);
        self::assertStringContainsString('80%', $october);

        $november = $crawler->filter('[data-cashflow-month="2026-11"]')->text();
        self::assertStringContainsString(AdminExtension::money(40000), $november);          // received: November's own and October's debt
        self::assertStringContainsString('долги '.AdminExtension::money(20000), $november);

        self::assertSelectorTextContains('[data-cashflow-total="planned"]', AdminExtension::money(95000));
        self::assertSelectorTextContains('[data-cashflow-total="received"]', AdminExtension::money(70000));
        self::assertSelectorTextContains('[data-cashflow-total="gap"]', AdminExtension::money(-25000));
    }

    public function testEveryReportDownloadsAsExcel(): void
    {
        $this->payment($this->vector, 'Сентябрь', '30000.50', '2026-09-01');
        $this->payment($this->petr, '=HYPERLINK("http://evil")', '1000', '2026-10-01');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/reports?view=debts');
        $this->client->request('GET', $crawler->filter('a[data-report-export]')->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('report-debts-2026-11-24.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $sheets = $this->readXlsx($this->client->getInternalResponse()->getContent());
        self::assertSame(['По клиентам', 'Просроченные платежи'], array_keys($sheets));
        self::assertSame('Дебиторка на 24.11.2026', $sheets['По клиентам'][0][0]);
        self::assertSame('ООО «Вектор»', $sheets['По клиентам'][2][0]);
        self::assertEqualsWithDelta(30000.5, $sheets['По клиентам'][2][3], 0.001); // a number, not text
        // a title that looks like a formula stays text
        $titles = array_column(\array_slice($sheets['Просроченные платежи'], 2), 1);
        self::assertContains("\u{2060}=HYPERLINK(\"http://evil\")", $titles);

        foreach (['sales' => 'По конструкциям', 'shipments' => 'По месяцам', 'cashflow' => 'План и факт'] as $view => $sheet) {
            $this->client->request('GET', '/admin/reports/export?view='.$view.'&from=2026-09&to=2026-11');
            self::assertResponseIsSuccessful();
            self::assertArrayHasKey($sheet, $this->readXlsx($this->client->getInternalResponse()->getContent()), $view);
        }
    }

    public function testAgentsCannotDownloadReports(): void
    {
        $agent = $this->createUser('agent@example.com', User::ROLE_AGENT);
        $this->client->loginUser($agent);
        $this->client->request('GET', '/admin/reports/export?view=debts');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardShowsTheMoneyOfTheMonth(): void
    {
        // received from the 1st to the 24th of each month
        $this->payment($this->vector, 'Ноябрь', '30000', '2026-11-05', paid: '2026-11-03');
        $this->payment($this->vector, 'Октябрь', '20000', '2026-10-05', paid: '2026-10-10');
        $this->payment($this->vector, 'Октябрь, конец', '50000', '2026-10-28', paid: '2026-10-28'); // after the 24th: not compared
        $this->payment($this->petr, 'Долг', '7000', '2026-11-01');                                    // overdue
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-kpi="received"]', AdminExtension::money(30000));
        self::assertSame('50', $crawler->filter('[data-kpi="received"] [data-change]')->first()->attr('data-change')); // +50% to 1–24 October
        self::assertStringContainsString('год назад: —', $crawler->filter('[data-kpi="received"]')->text());   // nothing a year ago
        self::assertSelectorTextContains('[data-kpi="debt"]', AdminExtension::money(7000));
        self::assertSelectorTextContains('[data-kpi="debt"]', 'просрочено');

        self::assertSame(50, FinanceKpi::change(150, 100));
        self::assertSame(-25, FinanceKpi::change(75, 100));
        self::assertNull(FinanceKpi::change(10, 0));
    }

    private function payment(User $client, string $title, string $amount, string $due, ?string $paid = null): Payment
    {
        $payment = (new Payment())->setClient($client)->setTitle($title)->setAmount($amount)->setDueDate(new \DateTimeImmutable($due));
        if (null !== $paid) {
            $payment->markPaid(new \DateTimeImmutable($paid.' 12:00'));
        }
        $this->em->persist($payment);

        return $payment;
    }

    /** @return array<string, list<list<mixed>>> sheet name => rows of cell values */
    private function readXlsx(string $content): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $reader = new Reader();
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);

        return $sheets;
    }
}
