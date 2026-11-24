<?php

namespace App\Service;

use App\Dto\ReportQuery;
use App\Entity\User;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A report of ReportController as an .xlsx workbook: a sheet per table of the report, numbers as numbers
 * (sums in rubles with kopecks), a bold header and the period on top.
 */
class ReportExport
{
    private const MONEY = '#,##0.00';

    private Style $bold;
    private Style $money;

    public function __construct()
    {
        $this->bold = (new Style())->withFontBold(true);
        $this->money = (new Style())->withFormat(self::MONEY);
    }

    /**
     * Writes the report to a temporary file and returns its path; the caller removes it.
     *
     * @param array<string, mixed> $report what ReportController::report() returns
     */
    public function write(array $report): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'report');
        $writer = new Writer();
        $writer->openToFile($path);

        $sheets = match ($report['view']) {
            ReportQuery::DEBTS => $this->debts($report),
            ReportQuery::CASHFLOW => $this->cashFlow($report),
            ReportQuery::SHIPMENTS => $this->shipments($report),
            default => $this->sales($report),
        };

        $first = true;
        foreach ($sheets as $name => [$header, $rows]) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $first = false;
            // Excel: at most 31 characters, none of \ / ? * [ ] :
            $sheet->setName(mb_substr($name, 0, 31));
            $writer->addRow(Row::fromValuesWithStyle([$this->title($report)], $this->bold));
            $writer->addRow(Row::fromValuesWithStyle($header, $this->bold));
            foreach ($rows as $row) {
                $writer->addRow($this->row($row));
            }
        }
        $writer->close();

        return $path;
    }

    /** "Дебиторка на 24.11.2026.xlsx", "Продажи, сентябрь 2026 — ноябрь 2026.xlsx" */
    public function filename(array $report): string
    {
        return $this->title($report).'.xlsx';
    }

    /** The same name in latin letters, for old browsers */
    public function asciiFilename(array $report): string
    {
        $view = $report['view'];
        $period = ReportQuery::DEBTS === $view
            ? $report['now']->format('Y-m-d')
            : $report['from']->format('Y-m').'_'.$report['to']->format('Y-m');

        return 'report-'.$view.'-'.$period.'.xlsx';
    }

    private function title(array $report): string
    {
        $label = ReportQuery::VIEW_LABELS[$report['view']] ?? 'Отчёт';
        if (ReportQuery::DEBTS === $report['view']) {
            return $label.' на '.$report['now']->format('d.m.Y');
        }
        $from = mb_strtolower(MonthCalendar::label($report['from']));
        $to = mb_strtolower(MonthCalendar::label($report['to']));

        return $label.', '.($from === $to ? $from : $from.' — '.$to);
    }

    /**
     * Floats are sums of money and get the money format; whole numbers and text stay as they are.
     *
     * @param list<string|int|float|null> $values
     */
    private function row(array $values): Row
    {
        $styles = [];
        foreach ($values as $i => $value) {
            if (\is_float($value)) {
                $styles[$i] = $this->money;
            }
            // a text starting with "=" would be written as a formula (a client named "=HYPERLINK(...)")
            if (\is_string($value) && str_starts_with($value, '=')) {
                $values[$i] = "\u{2060}".$value;
            }
        }

        return Row::fromValuesWithStyles($values, $styles);
    }

    private static function manager(?User $user): string
    {
        return null !== $user ? ($user->getName() ?: (string) $user->getEmail()) : 'Без менеджера';
    }

    /** @return array<string, array{0: list<string>, 1: list<list<string|int|float|null>>}> */
    private function sales(array $report): array
    {
        $t = $report['totals'];

        return [
            'По конструкциям' => [
                ['Конструкция', 'Стороны', 'Продаж', 'Месяцев сторон', 'Дней эфира', 'Размещение', 'Услуги', 'Итого', 'Оплачено', 'Доля, %'],
                [
                    ...array_map(static fn (array $r) => [
                        (string) $r['product']->getName(), implode(', ', $r['sides']), $r['sales'], $r['months'], $r['airtimeDays'],
                        (float) $r['placement'], (float) $r['services'], (float) $r['total'], (float) $r['paid'], (int) round($r['share']),
                    ], $report['products']),
                    ['Итого', null, $t['sales'], null, null, (float) $t['placement'], (float) $t['services'], (float) $t['total'], (float) $t['paid'], null],
                ],
            ],
            'По менеджерам' => [
                ['Оформил', 'Продаж', 'Размещение', 'Услуги', 'Итого', 'Оплачено', 'Средний чек', 'Сгорело броней'],
                array_map(static fn (array $r) => [
                    self::manager($r['user']), $r['sales'], (float) $r['placement'], (float) $r['services'], (float) $r['total'], (float) $r['paid'], (float) $r['average'], $r['burned'],
                ], $report['managers']),
            ],
        ];
    }

    /** @return array<string, array{0: list<string>, 1: list<list<string|int|float|null>>}> */
    private function shipments(array $report): array
    {
        $t = $report['totals'];

        return [
            'По месяцам' => [
                ['Месяц', 'Размещение', 'Услуги', 'Отгружено', 'Оплачено'],
                [
                    ...array_map(static fn (array $r) => [MonthCalendar::label($r['month']), (float) $r['placement'], (float) $r['services'], (float) $r['total'], (float) $r['paid']], $report['byMonth']),
                    ['Итого', (float) $t['placement'], (float) $t['services'], (float) $t['total'], (float) $t['paid']],
                ],
            ],
            'По конструкциям' => [
                ['Конструкция', 'Стороны', 'Броней', 'Дней', 'Размещение', 'Услуги', 'Отгружено', 'Оплачено', 'Доля, %'],
                array_map(static fn (array $r) => [
                    (string) $r['product']->getName(), implode(', ', $r['sides']), $r['bookings'], $r['days'],
                    (float) $r['placement'], (float) $r['services'], (float) $r['total'], (float) $r['paid'], (int) round($r['share']),
                ], $report['products']),
            ],
            'По менеджерам' => [
                ['Оформил', 'Броней', 'Размещение', 'Услуги', 'Отгружено', 'Оплачено', 'Средний чек'],
                array_map(static fn (array $r) => [
                    self::manager($r['user']), $r['bookings'], (float) $r['placement'], (float) $r['services'], (float) $r['total'], (float) $r['paid'], (float) $r['average'],
                ], $report['managers']),
            ],
        ];
    }

    /** @return array<string, array{0: list<string>, 1: list<list<string|int|float|null>>}> */
    private function debts(array $report): array
    {
        $t = $report['totals'];
        $buckets = array_map(static fn (string $label) => 'Просрочено '.$label, array_values(DebtReport::BUCKET_LABELS));

        return [
            'По клиентам' => [
                ['Клиент', 'ИНН', 'Платежей', 'Долг', 'Срок не наступил', 'Просрочено', ...$buckets, 'Дней просрочки (макс.)', 'Ближайший срок'],
                [
                    ...array_map(static fn (array $r) => [
                        (string) $r['client']->getClientTitle(), $r['client']->getInn(), $r['payments'],
                        (float) $r['debt'], (float) $r['current'], (float) $r['overdue'],
                        (float) $r['d30'], (float) $r['d60'], (float) $r['d90'], (float) $r['older'],
                        $r['maxDays'], $r['nextDue']?->format('d.m.Y'),
                    ], $report['clients']),
                    ['Итого', null, $t['payments'], (float) $t['debt'], (float) $t['current'], (float) $t['overdue'], (float) $t['d30'], (float) $t['d60'], (float) $t['d90'], (float) $t['older'], null, null],
                ],
            ],
            'Просроченные платежи' => [
                ['Клиент', 'За что', 'Сумма', 'Срок', 'Дней просрочки'],
                array_map(static fn (array $r) => [
                    (string) $r['payment']->getClient()->getClientTitle(), (string) $r['payment']->getTitle(), (float) $r['payment']->getAmount(),
                    $r['payment']->getDueDate()->format('d.m.Y'), $r['days'],
                ], $report['overdue']),
            ],
        ];
    }

    /** @return array<string, array{0: list<string>, 1: list<list<string|int|float|null>>}> */
    private function cashFlow(array $report): array
    {
        $t = $report['totals'];

        return [
            'План и факт' => [
                ['Месяц', 'План', 'Оплачено из плана', 'Не оплачено', 'Из них просрочено', 'Собрано, %', 'Поступило', 'Из них долги прошлых месяцев', 'Факт минус план'],
                [
                    ...array_map(static fn (array $r) => [
                        MonthCalendar::label($r['month']), (float) $r['planned'], (float) $r['plannedPaid'], (float) $r['plannedUnpaid'], (float) $r['overdue'],
                        $r['collection'], (float) $r['received'], (float) $r['receivedEarlier'], (float) $r['gap'],
                    ], $report['flow']),
                    ['Итого', (float) $t['planned'], (float) $t['plannedPaid'], (float) $t['plannedUnpaid'], (float) $t['overdue'], $t['collection'], (float) $t['received'], (float) $t['receivedEarlier'], (float) $t['gap']],
                ],
            ],
        ];
    }
}
