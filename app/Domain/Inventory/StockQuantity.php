<?php

namespace App\Domain\Inventory;

/**
 * 11.0a: stock quantities are decimal(14,3). All arithmetic happens in integer thousandths ("milli" units), never
 * in floats, so a balance can never drift. parse() is strict: digits only, up to 3 decimals, optional minus.
 */
final class StockQuantity
{
    /** decimal(14,3): 11 integer digits. */
    public const MAX_MILLI = 99999999999999;

    public static function parse(string $value, bool $signed = false): ?int
    {
        $value = trim($value);
        $pattern = $signed ? '/^-?\d{1,11}(\.\d{1,3})?$/' : '/^\d{1,11}(\.\d{1,3})?$/';

        if (! preg_match($pattern, $value)) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $milli = ((int) $whole) * 1000 + (int) str_pad($fraction, 3, '0');

        return $negative ? -$milli : $milli;
    }

    /** From a stored decimal (string, int or float) to milli units. */
    public static function fromDecimal(mixed $value): int
    {
        return (int) round(((float) $value) * 1000);
    }

    public static function format(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);

        return sprintf('%s%d.%03d', $sign, intdiv($abs, 1000), $abs % 1000);
    }
}
