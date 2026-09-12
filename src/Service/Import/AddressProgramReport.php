<?php

namespace App\Service\Import;

/**
 * What an address programme import did (or would do, in a dry run).
 */
final class AddressProgramReport
{
    public int $productsCreated = 0;
    public int $productsUpdated = 0;
    public int $sidesCreated = 0;
    public int $sidesUpdated = 0;

    /** @var list<string> dictionary entries created on the way ("Категория «Суперсайт»") */
    public array $dictionariesCreated = [];

    /** @var array<int, string> spreadsheet line => why the row was skipped */
    public array $skipped = [];

    /** @var list<string> "address, сторона X" rows without a price */
    public array $withoutPrice = [];

    /** Rows with a note in the "Бронь" column: not imported, a booking needs a client */
    public int $bookingNotes = 0;
}
