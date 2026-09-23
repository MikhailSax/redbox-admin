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

    /** Structures that got coordinates from their map link */
    public int $coordinatesSet = 0;

    /** @var list<string> "address: link" map links without a readable point */
    public array $coordinatesFailed = [];

    /** Photos downloaded and attached to sides (in a dry run: links that would be downloaded) */
    public int $photosAdded = 0;

    /** @var list<string> "address, сторона X: link" photo links that did not give an image */
    public array $photosFailed = [];

    /** @var list<string> dictionary entries created on the way ("Категория «Суперсайт»") */
    public array $dictionariesCreated = [];

    /** @var array<int, string> spreadsheet line => why the row was skipped */
    public array $skipped = [];

    /** @var list<string> "address, сторона X" rows without a price */
    public array $withoutPrice = [];

    /** Rows with a note in the "Бронь" column: not imported, a booking needs a client */
    public int $bookingNotes = 0;

    /** @var list<string> "«address» #12 → #4": structures of one address joined into one */
    public array $merged = [];

    /** @var list<string> "address, сторона X: Статика" sides whose own type was set or changed */
    public array $sideTypes = [];
}
