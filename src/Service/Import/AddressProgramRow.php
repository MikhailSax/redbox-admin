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
        /** Per month */
        public ?string $price,
        public ?string $bookingNote,
        public ?string $mapUrl = null,
        public ?string $photoUrl = null,
        /** For 14 days as a whole */
        public ?string $price2Weeks = null,
        /** Per month when placed for 3 months */
        public ?string $price3Months = null,
        /** Per month when placed for 6 months */
        public ?string $price6Months = null,
        /** "3 500,00 (баннер)" */
        public ?string $printText = null,
        /** Screens: slot length, "5 сек" */
        public ?string $slotText = null,
        /** Screens: block length, "60 сек" */
        public ?string $blockText = null,
    ) {
    }
}
