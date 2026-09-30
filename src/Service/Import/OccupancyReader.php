<?php

namespace App\Service\Import;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;

/**
 * Reads the occupancy spreadsheet of screens and billboards ("Экраны и щиты занятость ... .xlsx"), a sheet per month.
 *
 * A sheet is a column of tables, one per structure or side. A table starts with a header row: the structure in the
 * second column ("Экран/ Борсоева сторона А"), "Период размещения" in the third, "номер в схеме" and the number
 * next to it. The rows below it have a slot number ("1", "2", …) or a side ("А", "B1") in the first column,
 * the client in the second and the period in the third. Rows with no slot/side ("Итого:", notes) are left out.
 */
class OccupancyReader
{
    private const CODE = 0;
    private const CLIENT = 1;
    private const PERIOD = 2;

    /**
     * @return list<string> visible sheet names
     */
    public function sheetNames(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        try {
            $names = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->isVisible()) {
                    $names[] = $sheet->getName();
                }
            }

            return $names;
        } finally {
            $reader->close();
        }
    }

    /**
     * @param string|null $sheetName null = the last visible sheet (the current month comes last)
     *
     * @return list<OccupancyBlock>
     *
     * @throws \InvalidArgumentException when there is no such sheet
     */
    public function read(string $path, ?string $sheetName = null): array
    {
        // empty rows kept, so the lines reported are the ones Excel shows
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);

        try {
            $blocks = [];
            $header = null;
            $rows = [];
            foreach ($this->findSheet($reader, $sheetName)->getRowIterator() as $line => $row) {
                $cells = array_map(self::text(...), $row->toArray());
                $lower = array_map(static fn (?string $cell) => mb_strtolower((string) $cell), $cells);

                $numberAt = array_search('номер в схеме', $lower, true);
                if (false !== $numberAt || 'период размещения' === ($lower[self::PERIOD] ?? null)) {
                    if (null !== $header) {
                        $blocks[] = new OccupancyBlock($header['line'], $header['title'], $header['number'], $rows);
                    }
                    $number = false !== $numberAt ? ($cells[$numberAt + 1] ?? null) : null;
                    $header = [
                        'line' => $line,
                        'title' => $cells[self::CLIENT] ?? '',
                        'number' => null !== $number && 'б/н' !== mb_strtolower($number) ? $number : null,
                    ];
                    $rows = [];
                    continue;
                }

                $code = $cells[self::CODE] ?? null;
                $client = $cells[self::CLIENT] ?? null;
                if (null !== $header && null !== $code && null !== $client) {
                    $rows[] = new OccupancyRow($line, $code, $client, $cells[self::PERIOD] ?? null);
                }
            }
            if (null !== $header) {
                $blocks[] = new OccupancyBlock($header['line'], $header['title'], $header['number'], $rows);
            }

            return $blocks;
        } finally {
            $reader->close();
        }
    }

    private function findSheet(Reader $reader, ?string $sheetName): Sheet
    {
        $found = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            if (null !== $sheetName ? $sheet->getName() === $sheetName : $sheet->isVisible()) {
                $found = $sheet;
                if (null !== $sheetName) {
                    break;
                }
            }
        }

        return $found ?? throw new \InvalidArgumentException(null !== $sheetName ? \sprintf('Лист «%s» не найден', $sheetName) : 'В файле нет видимых листов');
    }

    /**
     * Trimmed text; numbers as they are ("884"), a date typed as a date as "15.07.2026", empty cells as null.
     */
    private static function text(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        } elseif ($value instanceof \DateTimeInterface) {
            $value = $value->format('d.m.Y');
        } elseif (!\is_string($value)) {
            return null;
        }
        $value = str_replace(["\u{200B}", "\u{FEFF}", "\u{00A0}"], ['', '', ' '], $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' !== $value ? $value : null;
    }
}
