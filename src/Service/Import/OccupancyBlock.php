<?php

namespace App\Service\Import;

/**
 * One table of the occupancy spreadsheet: a structure (or one of its sides) and the clients placed on it.
 */
final readonly class OccupancyBlock
{
    /**
     * @param list<OccupancyRow> $rows the rows with a client
     */
    public function __construct(
        public int $line,
        /** "Экран/ Борсоева сторона А", "Щит, ул. Мокрова, 32" */
        public string $title,
        /** "Номер в схеме": 884, "5/25"; null when left empty or "б/н" */
        public ?string $schemeNumber,
        public array $rows,
    ) {
    }
}
