<?php

namespace App\Domain\Marketing\Sending;

use App\Events\CampaignProgressUpdated;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads a campaign's current counters and broadcasts them (9.4d).
 *
 * A broadcast outage must never stop or fail a send, so every failure is reported and swallowed.
 */
final class CampaignProgressBroadcaster
{
    public static function broadcast(int $tenantId, int $campaignId): void
    {
        try {
            $row = DB::table('campaigns')
                ->where('tenant_id', $tenantId)
                ->where('id', $campaignId)
                ->first(['id', 'status', 'lock_version', 'recipients_count', 'sent_count', 'failed_count']);

            if ($row === null) {
                return;
            }

            event(new CampaignProgressUpdated(
                $tenantId,
                (int) $row->id,
                (string) $row->status,
                (int) $row->lock_version,
                (int) $row->recipients_count,
                (int) $row->sent_count,
                (int) $row->failed_count,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Defers the broadcast until the surrounding transaction commits (the row is read after commit). */
    public static function afterCommit(int $tenantId, int $campaignId): void
    {
        DB::afterCommit(static fn () => self::broadcast($tenantId, $campaignId));
    }
}