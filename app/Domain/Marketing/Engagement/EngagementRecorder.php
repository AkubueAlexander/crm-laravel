<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Engagement;

use App\Domain\Marketing\Sending\EmailSuppressions;
use Illuminate\Support\Facades\DB;

/**
 * 9.5: records engagement on campaign_recipients (explicit tenant predicates, plain DB access: it runs from public
 * endpoints and webhooks with no session or TenantContext). Each fact is stored once per recipient and the campaign
 * counters are RECOMPUTED from the rows, never incremented, so repeated pixel loads, double clicks and provider
 * retries cannot drift them. lock_version is untouched: counters are not part of the optimistic lock.
 * Every method returns false when the recipient does not exist in that tenant (nothing is written then).
 */
final class EngagementRecorder
{
    public static function opened(int $tenantId, int $recipientId): bool
    {
        $recipient = self::recipient($tenantId, $recipientId);
        if ($recipient === null) {
            return false;
        }

        $changed = DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('id', $recipientId)
            ->whereNull('opened_at')
            ->update(['opened_at' => now(), 'updated_at' => now()]);

        if ($changed > 0) {
            self::syncCounters($tenantId, (int) $recipient->campaign_id);
        }

        return true;
    }

    /** A click implies an open (image-blocking clients never load the pixel). */
    public static function clicked(int $tenantId, int $recipientId): bool
    {
        $recipient = self::recipient($tenantId, $recipientId);
        if ($recipient === null) {
            return false;
        }

        $changed = DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('id', $recipientId)
            ->where(function ($q) {
                $q->whereNull('clicked_at')->orWhereNull('opened_at');
            })
            ->update([
                'clicked_at' => DB::raw('coalesce(clicked_at, now())'),
                'opened_at' => DB::raw('coalesce(opened_at, now())'),
                'updated_at' => now(),
            ]);

        if ($changed > 0) {
            self::syncCounters($tenantId, (int) $recipient->campaign_id);
        }

        return true;
    }

    /** A hard (permanent) bounce also suppresses the address for the whole tenant; a soft bounce is only counted. */
    public static function bounced(int $tenantId, int $recipientId, bool $permanent = true): bool
    {
        $recipient = self::recipient($tenantId, $recipientId);
        if ($recipient === null) {
            return false;
        }

        $changed = DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('id', $recipientId)
            ->whereNull('bounced_at')
            ->update(['bounced_at' => now(), 'updated_at' => now()]);

        if ($changed > 0) {
            self::syncCounters($tenantId, (int) $recipient->campaign_id);
        }

        if ($permanent) {
            EmailSuppressions::suppress($tenantId, (string) $recipient->email, 'bounced', (int) $recipient->campaign_id);
        }

        return true;
    }

    /** A spam complaint always suppresses the address. It is not part of bounced_count. */
    public static function complained(int $tenantId, int $recipientId): bool
    {
        $recipient = self::recipient($tenantId, $recipientId);
        if ($recipient === null) {
            return false;
        }

        DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('id', $recipientId)
            ->whereNull('complained_at')
            ->update(['complained_at' => now(), 'updated_at' => now()]);

        EmailSuppressions::suppress($tenantId, (string) $recipient->email, 'complaint', (int) $recipient->campaign_id);

        return true;
    }

    /** Unique-recipient counts: one address counts once however many times it opened or clicked. */
    public static function syncCounters(int $tenantId, int $campaignId): void
    {
        $counts = DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->selectRaw('count(*) filter (where opened_at is not null) as opened, count(*) filter (where clicked_at is not null) as clicked, count(*) filter (where bounced_at is not null) as bounced')
            ->first();

        DB::table('campaigns')
            ->where('tenant_id', $tenantId)
            ->where('id', $campaignId)
            ->update([
                'opened_count' => (int) $counts->opened,
                'clicked_count' => (int) $counts->clicked,
                'bounced_count' => (int) $counts->bounced,
                'updated_at' => now(),
            ]);
    }

    private static function recipient(int $tenantId, int $recipientId): ?object
    {
        return DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('id', $recipientId)
            ->first(['campaign_id', 'email']);
    }
}