<?php

namespace App\Service\Import;

use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;

/**
 * Reads the address programme spreadsheet ("Адресная программа ... .xlsx").
 *
 * A sheet holds several tables (digital billboards, billboards and city formats, regional billboards), each with its
 * own header row starting with "Номер в схеме" / "Номер"; columns are found by header text, so their order may differ.
 * Title rows above the tables ("Улан-Удэ", "Районы Республики ...") tell the city from the regional districts.
 * Photo and map links are not read.
 */
class AddressProgramReader
{
    /** header text (lower case) => row field */
    private const HEADERS = [
        'номер в схеме' => 'number',
        'номер' => 'number',
        'адрес' => 'address',
        'сторона' => 'side',
        'формат' => 'format',
        'тип конструкции, размер' => 'format',
        'тип конструкции' => 'format',
        'размер, м' => 'size',
        'месяц' => 'price',
        'стоимость размещения' => 'price',
        'бронь' => 'booking',
    ];

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
     * @param string|null $sheetName null = the last visible sheet (the newest price list comes last)
     *
     * @return list<AddressProgramRow>
     */
    public function read(string $path, ?string $sheetName = null): array
    {
        $reader = new Reader();
        $reader->open($path);

        try {
            $sheet = $this->findSheet($reader, $sheetName);
            $rows = [];
            $columns = null;
            $region = AddressProgramRow::REGION_CITY;

            foreach ($sheet->getRowIterator() as $line => $row) {
                $cells = array_map(self::text(...), $row->toArray());
                $first = mb_strtolower($cells[0] ?? '');

                if ('number' === (self::HEADERS[$first] ?? null)) {
                    $columns = self::columns($cells);
                    continue;
                }

                // Title row: only the first cell is filled
                if (null !== ($cells[0] ?? null) && 1 === \count(array_filter($cells, static fn (?string $v) => null !== $v))) {
                    if (str_starts_with($first, 'районы')) {
                        $region = AddressProgramRow::REGION_DISTRICTS;
                    } elseif (str_starts_with($first, 'улан-удэ')) {
                        $region = AddressProgramRow::REGION_CITY;
                    }
                    continue;
                }

                $get = static fn (string $field) => null !== $columns && isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;
                if (null === $columns || null === $get('address') || null === $get('side') || null === $get('format')) {
                    continue;
                }

                $rows[] = new AddressProgramRow(
                    line: $line,
                    region: $region,
                    number: $get('number'),
                    address: $get('address'),
                    side: $get('side'),
                    format: $get('format'),
                    sizeText: $get('size'),
                    price: $get('price'),
                    bookingNote: $get('booking'),
                );
            }

            return $rows;
        } finally {
            $reader->close();
        }
    }

    /**
     * @param list<string|null> $cells
     *
     * @return array<string, int> field => column index (the first matching column wins)
     */
    private static function columns(array $cells): array
    {
        $columns = [];
        foreach ($cells as $index => $cell) {
            $field = self::HEADERS[mb_strtolower((string) $cell)] ?? null;
            if (null !== $field && !isset($columns[$field])) {
                $columns[$field] = $index;
            }
        }

        return $columns;
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
     * Cell value as trimmed text: numbers keep their value ("858", "23000"), empty cells become null.
     * Zero-width and non-breaking spaces from copy-pasted addresses are cleaned up.
     */
    private static function text(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) $value;
        } elseif (\is_array($value)) { // rich text runs
            $value = implode('', array_map(static fn ($run) => (string) $run->text, $value));
        } elseif (!\is_string($value)) {
            return null;
        }

        $value = str_replace(["\u{200B}", "\u{FEFF}", "\u{00A0}"], ['', '', ' '], $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' !== $value ? $value : null;
    }
}
