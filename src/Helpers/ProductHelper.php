<?php

namespace App\Helpers;

/**
 * Structure sizes (width × height, metres) kept as constants instead of an entity.
 * Product::$size stores the key; forms, lists and the importer read labels from here.
 */
final class ProductHelper
{
    public const SIZE_1_2X1_8 = '1.2x1.8';
    public const SIZE_2_3X7_7 = '2.3x7.7';
    public const SIZE_3_3X7_7 = '3.3x7.7';
    public const SIZE_6X3 = '6x3';
    public const SIZE_10X3_5 = '10x3.5';
    public const SIZE_10_5X3_5 = '10.5x3.5';
    public const SIZE_12X4 = '12x4';
    public const SIZE_15X5 = '15x5';

    /** key => label */
    public const SIZES = [
        self::SIZE_1_2X1_8 => '1,2 × 1,8 м',
        self::SIZE_2_3X7_7 => '2,3 × 7,7 м',
        self::SIZE_3_3X7_7 => '3,3 × 7,7 м',
        self::SIZE_6X3 => '6 × 3 м',
        self::SIZE_10X3_5 => '10 × 3,5 м',
        self::SIZE_10_5X3_5 => '10,5 × 3,5 м',
        self::SIZE_12X4 => '12 × 4 м',
        self::SIZE_15X5 => '15 × 5 м',
    ];

    /**
     * @return list<string>
     */
    public static function sizeKeys(): array
    {
        return array_keys(self::SIZES);
    }

    /**
     * For ChoiceType: label => key.
     *
     * @return array<string, string>
     */
    public static function sizeChoices(): array
    {
        return array_flip(self::SIZES);
    }

    public static function sizeLabel(?string $size): ?string
    {
        return null !== $size ? (self::SIZES[$size] ?? $size) : null;
    }

    /**
     * Finds a known size in free text: "Щит 6Х3", "12*4", "10,5*3,5", "Фасад дома 3,3*7,7 м.".
     */
    public static function sizeFromText(?string $text): ?string
    {
        if (null === $text || !preg_match('/(\d+(?:[.,]\d+)?)\s*[xхXХ*×]\s*(\d+(?:[.,]\d+)?)/u', $text, $m)) {
            return null;
        }

        $key = self::number($m[1]).'x'.self::number($m[2]);

        return isset(self::SIZES[$key]) ? $key : null;
    }

    /** "10,50" → "10.5", "6" → "6" */
    private static function number(string $value): string
    {
        $value = str_replace(',', '.', $value);

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
