<?php

namespace App\Service\Import;

/**
 * A client placed on a structure, as a line of the occupancy spreadsheet.
 */
final readonly class OccupancyRow
{
    public function __construct(
        public int $line,
        /** First column: a slot number of a screen ("3") or a side ("А", "B2") */
        public string $code,
        public string $client,
        /** "01.10.2026-31.10.2026", "сентябрь", "до конца года"; null when left empty */
        public ?string $period,
    ) {
    }
}
