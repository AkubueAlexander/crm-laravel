<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Sending;

use App\Domain\Marketing\Segments\SegmentQueryBuilder;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * 9.4b: freezes a campaign's audience into campaign_recipients.
 *
 * Callers (the 9.4c start job) MUST set TenantContext first: the segment query goes through the contacts
 * global tenant scope, which matches nothing without it. The tenant is also an explicit argument and every
 * query carries an explicit tenant predicate.
 *
 * Idempotent and resumable: if the campaign already has recipient rows the snapshot is NOT taken again
 * (the audience stays frozen), and inserts use insertOrIgnore against the unique indexes, so a crash in
 * the middle of a snapshot followed by a retry cannot duplicate anyone.
 */
final class RecipientSnapshot
{
    public const CHUNK = 500;

    /** @return array{already_taken: bool, total: int, skipped_suppressed: int, skipped_invalid: int} */
    public static function take(int $tenantId, int $campaignId): array
    {
        $campaign = DB::table('campaigns')
            ->where('tenant_id', $tenantId)
            ->where('id', $campaignId)
            ->first(['id', 'segment_id']);

        if ($campaign === null) {
            throw (new ModelNotFoundException())->setModel(Campaign::class, [$campaignId]);
        }
        if ($campaign->segment_id === null) {
            throw new \DomainException('The campaign has no segment.');
        }

        $segment = DB::table('segments')
            ->where('tenant_id', $tenantId)
            ->where('id', $campaign->segment_id)
            ->first(['match_mode', 'conditions']);

        if ($segment === null) {
            throw new \DomainException('The campaign segment no longer exists.');
        }

        $existing = self::countFor($tenantId, $campaignId);
        if ($existing > 0) {
            return ['already_taken' => true, 'total' => $existing, 'skipped_suppressed' => 0, 'skipped_invalid' => 0];
        }

        $conditions = json_decode((string) $segment->conditions, true);
        $conditions = is_array($conditions) ? $conditions : [];

        $suppressedCount = 0;
        $invalidCount = 0;

        SegmentQueryBuilder::contactsFor($tenantId, (string) $segment->match_mode, $conditions)
            ->whereNotNull('contacts.email')
            ->select(['contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.email', 'contacts.job_title'])
            ->chunkById(
                self::CHUNK,
                function ($contacts) use ($tenantId, $campaignId, &$suppressedCount, &$invalidCount): void {
                    $candidates = [];
                    foreach ($contacts as $contact) {
                        $email = trim((string) $contact->email);
                        $normalized = EmailSuppressions::normalize($email);

                        if ($normalized === '' || strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
                            $invalidCount++;

                            continue;
                        }
                        // The same address on two contacts is mailed once (first contact wins).
                        if (isset($candidates[$normalized])) {
                            continue;
                        }
                        $candidates[$normalized] = [$contact, $email];
                    }

                    $suppressed = EmailSuppressions::suppressedAmong($tenantId, array_keys($candidates));

                    $rows = [];
                    foreach ($candidates as $normalized => [$contact, $email]) {
                        if (isset($suppressed[$normalized])) {
                            $suppressedCount++;

                            continue;
                        }
                        $name = trim(((string) $contact->first_name).' '.((string) $contact->last_name));
                        $rows[] = [
                            'tenant_id' => $tenantId,
                            'campaign_id' => $campaignId,
                            'contact_id' => $contact->id,
                            'email' => $email,
                            'email_normalized' => $normalized,
                            'name' => $name === '' ? null : $name,
                            'job_title' => $contact->job_title === null ? null : trim((string) $contact->job_title),
                            'status' => 'pending',
                            'attempts' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    if ($rows !== []) {
                        DB::table('campaign_recipients')->insertOrIgnore($rows);
                    }
                },
                'contacts.id',
                'id',
            );

        return [
            'already_taken' => false,
            'total' => self::countFor($tenantId, $campaignId),
            'skipped_suppressed' => $suppressedCount,
            'skipped_invalid' => $invalidCount,
        ];
    }

    private static function countFor(int $tenantId, int $campaignId): int
    {
        return DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->count();
    }
}