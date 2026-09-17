<?php

namespace App\Service\Import;

/**
 * One side of a structure as listed in the address programme spreadsheet.
 */
final readonly class AddressProgramRow
{
    public const REGION_CITY = 'city';
    public const REGION_DISTRICTS = 'districts';

    public function __construct(
        public int $line,
        public string $region,
        public ?string $number,
        public string $address,
        public string $side,
        public string $format,
        public ?string $sizeText,
        public ?string $price,
        public ?string $bookingNote,
        public ?string $mapUrl = null,
        public ?string $photoUrl = null,
    ) {
    }
}
