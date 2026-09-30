<?php

namespace App\Service\Import;

/**
 * What an occupancy import did (or would do, in a dry run).
 */
final class OccupancyReport
{
    public int $created = 0;

    /** Rows already in the CRM as a booking of the same side, client and days */
    public int $existing = 0;

    /** @var list<string> client cards made for names not found in the CRM */
    public array $clientsCreated = [];

    /** @var list<string> "строка 141 «Экран Бурвод (Вегос М)»: …" tables whose structure or side was not found */
    public array $blocksSkipped = [];

    /** @var array<int, string> spreadsheet line => why the row was not imported */
    public array $rowsSkipped = [];
}
