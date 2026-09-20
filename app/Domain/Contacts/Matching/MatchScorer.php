<?php

namespace App\Domain\Contacts\Matching;

/**
 * Stage 2 of duplicate detection. Pure PHP: no DB, no framework, Pest-unit-testable.
 *
 * Score (0-100) = name similarity * 80 + same-email 20 + same-phone 15, capped at 100.
 * A shared email is near-conclusive, so it also floors the score at 90.
 */
final class MatchScorer
{
    private const NAME_WEIGHT = 0.80;
    private const EMAIL_WEIGHT = 0.20;
    private const PHONE_WEIGHT = 0.15;
    private const EMAIL_FLOOR = 0.90;
    /** Below this raw name similarity, the name contributes nothing (kills noise). */
    private const NAME_MIN_SIMILARITY = 0.75;

    public function score(ContactCandidate $a, ContactCandidate $b): MatchScore
    {
        $reasons = [];
        $total = 0.0;

        $nameA = $a->normalizedName();
        $nameB = $b->normalizedName();

        if ($nameA !== '' && $nameB !== '') {
            // Token-sorted comparison handles "Smith John" vs "John Smith".
            $similarity = max(
                self::jaroWinkler($nameA, $nameB),
                self::jaroWinkler(self::sortTokens($nameA), self::sortTokens($nameB)),
            );

            if ($similarity >= self::NAME_MIN_SIMILARITY) {
                $total += $similarity * self::NAME_WEIGHT;

                $reasons[] = $similarity >= 0.99
                    ? 'Same name'
                    : sprintf('Similar name (%d%%)', (int) round($similarity * 100));
            }
        }

        $emailA = $a->normalizedEmail();
        $emailMatch = $emailA !== null && $emailA === $b->normalizedEmail();
        if ($emailMatch) {
            $total += self::EMAIL_WEIGHT;
            $reasons[] = 'Same email';
        }

        $phoneA = $a->phoneKey();
        if ($phoneA !== null && $phoneA === $b->phoneKey()) {
            $total += self::PHONE_WEIGHT;
            $reasons[] = 'Same phone number';
        }

        if ($emailMatch) {
            $total = max($total, self::EMAIL_FLOOR);
        }

        return new MatchScore((int) round(min($total, 1.0) * 100), $reasons);
    }

    private static function sortTokens(string $text): string
    {
        $tokens = explode(' ', $text);
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** Multibyte-safe Jaro-Winkler similarity, 0.0 - 1.0. */
    public static function jaroWinkler(string $a, string $b): float
    {
        if ($a === $b) {
            return $a === '' ? 0.0 : 1.0;
        }

        $s1 = mb_str_split($a);
        $s2 = mb_str_split($b);
        $l1 = count($s1);
        $l2 = count($s2);

        if ($l1 === 0 || $l2 === 0) {
            return 0.0;
        }

        $window = max(0, intdiv(max($l1, $l2), 2) - 1);
        $matched1 = array_fill(0, $l1, false);
        $matched2 = array_fill(0, $l2, false);
        $matches = 0;

        for ($i = 0; $i < $l1; $i++) {
            $start = max(0, $i - $window);
            $end = min($i + $window + 1, $l2);

            for ($j = $start; $j < $end; $j++) {
                if ($matched2[$j] || $s1[$i] !== $s2[$j]) {
                    continue;
                }
                $matched1[$i] = $matched2[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $l1; $i++) {
            if (! $matched1[$i]) {
                continue;
            }
            while (! $matched2[$k]) {
                $k++;
            }
            if ($s1[$i] !== $s2[$k]) {
                $transpositions++;
            }
            $k++;
        }

        $jaro = (
                $matches / $l1
                + $matches / $l2
                + ($matches - $transpositions / 2) / $matches
            ) / 3;

        $prefix = 0;
        for ($i = 0, $max = min(4, $l1, $l2); $i < $max; $i++) {
            if ($s1[$i] !== $s2[$i]) {
                break;
            }
            $prefix++;
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }
}
