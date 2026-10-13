<?php

namespace App\Helpers;

/**
 * Structure sizes: width × height in metres.
 * Product::$size stores them as a key, "6x3" or "10.5x3.5" (dot decimals, no trailing zeros);
 * the constants are the common formats the importer and the tests refer to.
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

    /** Widest and highest structure a size may describe, metres */
    public const MAX_METRES = 100;

    /** Key of a width and a height in metres: (10.5, 3.5) → "10.5x3.5" */
    public static function sizeKey(float $width, float $height): string
    {
        return self::number(number_format($width, 2, '.', '')).'x'.self::number(number_format($height, 2, '.', ''));
    }

    /**
     * Width and height of a key: "10.5x3.5" → [10.5, 3.5]; null when it isn't one.
     *
     * @return array{float, float}|null
     */
    public static function dimensions(?string $size): ?array
    {
        if (null === $size || !preg_match('/^(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)$/', $size, $m)) {
            return null;
        }

        return [(float) $m[1], (float) $m[2]];
    }

    /** "10.5x3.5" → "10,5 × 3,5 м" */
    public static function sizeLabel(?string $size): ?string
    {
        if (null === $size) {
            return null;
        }
        $dimensions = self::dimensions($size);
        if (null === $dimensions) {
            return $size;
        }

        return str_replace(['.', 'x'], [',', ' × '], self::sizeKey(...$dimensions)).' м';
    }

    /**
     * Finds a size in free text: "Щит 6Х3", "12*4", "10,5*3,5", "Фасад дома 3,3*7,7 м.".
     */
    public static function sizeFromText(?string $text): ?string
    {
        if (null === $text || !preg_match('/(\d+(?:[.,]\d+)?)\s*[xхXХ*×]\s*(\d+(?:[.,]\d+)?)/u', $text, $m)) {
            return null;
        }
        $width = (float) str_replace(',', '.', $m[1]);
        $height = (float) str_replace(',', '.', $m[2]);

        return $width > 0 && $height > 0 && $width <= self::MAX_METRES && $height <= self::MAX_METRES ? self::sizeKey($width, $height) : null;
    }

    /** "10.50" → "10.5", "6.00" → "6" */
    private static function number(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
