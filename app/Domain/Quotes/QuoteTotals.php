<?php

namespace App\Domain\Quotes;

/**
 * 10.1: pure, framework-free quote arithmetic in integer cents (no float money is ever stored).
 * Tax rates here are a placeholder keyed by the product's tax category; 10.4 delegates real tax to an external
 * service server-side. The client never sends totals or rates, and never computes them for storage.
 */
final class QuoteTotals
{
    /** Basis points (1 bp = 0.01%). standard 7.5%, reduced 5%. */
    public const RATES_BPS = ['standard' => 750, 'reduced' => 500, 'zero' => 0, 'exempt' => 0];

    /**
     * @param list<array{quantity: float|int, unit_cents: int, discount_percent: float|int, tax_category: string}> $lines
     * @return array{lines: list<array<string, mixed>>, subtotal_cents: int, tax_cents: int, total_cents: int}
     */
    public static function compute(array $lines): array
    {
        $subtotal = 0;
        $tax = 0;
        $out = [];

        foreach ($lines as $line) {
            $net = (int) round($line['quantity'] * $line['unit_cents'] * (100 - $line['discount_percent']) / 100, 0, PHP_ROUND_HALF_UP);
            $bps = self::RATES_BPS[$line['tax_category']] ?? 0;
            $lineTax = (int) round($net * $bps / 10000, 0, PHP_ROUND_HALF_UP);

            $out[] = $line + ['net_cents' => $net, 'tax_cents' => $lineTax, 'rate_bps' => $bps];
            $subtotal += $net;
            $tax += $lineTax;
        }

        return ['lines' => $out, 'subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax];
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
