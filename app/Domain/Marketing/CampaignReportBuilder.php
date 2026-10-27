<?php

namespace App\Domain\Marketing;

use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 9.10: read-only engagement report for one campaign. Totals come from the per-recipient rows (9.4b/9.5),
 * which are the source of truth; the denormalised campaign counters are not used here. Every query carries
 * an explicit tenant predicate.
 */
final class CampaignReportBuilder
{
    /** @return array<string, mixed> */
    public function build(Campaign $campaign): array
    {
        $tenantId = (int) $campaign->tenant_id;
        $campaignId = (int) $campaign->id;

        $row = DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->selectRaw(
                "count(*) as recipients, "
                ."count(*) filter (where status = 'sent') as sent, "
                ."count(*) filter (where status = 'failed') as failed, "
                ."count(*) filter (where status = 'skipped') as skipped, "
                ."count(*) filter (where status = 'pending') as pending, "
                .'count(opened_at) as opened, count(clicked_at) as clicked, '
                .'count(bounced_at) as bounced, count(complained_at) as complained'
            )
            ->first();

        $sent = (int) ($row->sent ?? 0);
        $bounced = (int) ($row->bounced ?? 0);
        $delivered = max($sent - $bounced, 0);
        $opened = (int) ($row->opened ?? 0);
        $clicked = (int) ($row->clicked ?? 0);

        $unsubscribed = (int) DB::table('email_suppressions')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->where('reason', 'unsubscribed')
            ->count();

        return [
            'campaign' => [
                'id' => $campaignId,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'recipients_count' => (int) $campaign->recipients_count,
                'started_at' => $campaign->started_at?->toIso8601String(),
                'completed_at' => $campaign->completed_at?->toIso8601String(),
                'sent_at' => $campaign->sent_at?->toIso8601String(),
            ],
            'totals' => [
                'recipients' => (int) ($row->recipients ?? 0),
                'sent' => $sent,
                'failed' => (int) ($row->failed ?? 0),
                'skipped' => (int) ($row->skipped ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'delivered' => $delivered,
                'opened' => $opened,
                'clicked' => $clicked,
                'bounced' => $bounced,
                'complained' => (int) ($row->complained ?? 0),
                'unsubscribed' => $unsubscribed,
            ],
            'rates' => [
                'open' => $this->rate($opened, $delivered),
                'click' => $this->rate($clicked, $delivered),
                'bounce' => $this->rate($bounced, $sent),
                'unsubscribe' => $this->rate($unsubscribed, $delivered),
            ],
            'series' => $this->series($tenantId, $campaignId),
            'attribution' => $this->attribution($tenantId, $campaign),
        ];
    }

    private function rate(int $n, int $d): ?float
    {
        return $d > 0 ? round($n / $d * 100, 1) : null;
    }

    /** @return list<array{date: string, sent: int, opened: int, clicked: int, bounced: int}> */
    private function series(int $tenantId, int $campaignId): array
    {
        $days = [];
        foreach (['sent' => 'sent_at', 'opened' => 'opened_at', 'clicked' => 'clicked_at', 'bounced' => 'bounced_at'] as $key => $column) {
            $counts = DB::table('campaign_recipients')
                ->where('tenant_id', $tenantId)
                ->where('campaign_id', $campaignId)
                ->whereNotNull($column)
                ->selectRaw("to_char($column, 'YYYY-MM-DD') as d, count(*) as c")
                ->groupBy('d')
                ->pluck('c', 'd');

            foreach ($counts as $date => $count) {
                $days[$date] ??= ['date' => (string) $date, 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0];
                $days[$date][$key] = (int) $count;
            }
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * Campaign linkage is by free-text utm_campaign (9.9): matches the campaign name or its slug, case-insensitively.
     *
     * @return array{leads: int, deals: int}
     */
    private function attribution(int $tenantId, Campaign $campaign): array
    {
        $names = array_values(array_unique(array_filter([
            mb_strtolower((string) $campaign->name),
            Str::slug((string) $campaign->name),
        ], fn ($n) => $n !== '')));

        if ($names === []) {
            return ['leads' => 0, 'deals' => 0];
        }

        $sql = 'lower(utm_campaign) in ('.implode(',', array_fill(0, count($names), '?')).')';

        return [
            'leads' => Lead::query()->where('tenant_id', $tenantId)->whereRaw($sql, $names)->count(),
            'deals' => Deal::query()->where('tenant_id', $tenantId)->whereRaw($sql, $names)->count(),
        ];
    }
}
