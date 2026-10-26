<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Sending;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 9.4b: the per-tenant suppression list. Plain DB access with explicit tenant predicates, so it is safe
 * from queue workers, webhooks (9.5) and the public unsubscribe endpoint (9.4c) alike.
 */
final class EmailSuppressions
{
    public const REASONS = ['unsubscribed', 'bounced', 'complaint', 'manual'];

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Returns true when the address was newly suppressed; an existing suppression is never overwritten. */
    public static function suppress(int $tenantId, string $email, string $reason, ?int $campaignId = null): bool
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown suppression reason: {$reason}");
        }

        $normalized = self::normalize($email);
        if ($normalized === '') {
            throw new InvalidArgumentException('Cannot suppress an empty address.');
        }

        return DB::table('email_suppressions')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'email_normalized' => $normalized,
            'reason' => $reason,
            'campaign_id' => $campaignId,
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    public static function isSuppressed(int $tenantId, string $email): bool
    {
        return DB::table('email_suppressions')
            ->where('tenant_id', $tenantId)
            ->where('email_normalized', self::normalize($email))
            ->exists();
    }

    /**
     * @param  list<string>  $normalizedEmails
     * @return array<string, true> the subset that is suppressed, keyed by address
     */
    public static function suppressedAmong(int $tenantId, array $normalizedEmails): array
    {
        $found = [];
        foreach (array_chunk($normalizedEmails, 500) as $chunk) {
            $rows = DB::table('email_suppressions')
                ->where('tenant_id', $tenantId)
                ->whereIn('email_normalized', $chunk)
                ->pluck('email_normalized');
            foreach ($rows as $email) {
                $found[(string) $email] = true;
            }
        }

        return $found;
    }
}