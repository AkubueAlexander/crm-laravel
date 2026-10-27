<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 9.10: explicit whitelist over CampaignReportBuilder's array. No tenant id, no recipient emails.
 */
class CampaignReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $c = $r['campaign'];
        $t = $r['totals'];
        $rt = $r['rates'];

        return [
            'campaign' => [
                'id' => (int) $c['id'],
                'name' => $c['name'],
                'status' => $c['status'],
                'recipients_count' => (int) $c['recipients_count'],
                'started_at' => $c['started_at'],
                'completed_at' => $c['completed_at'],
                'sent_at' => $c['sent_at'],
            ],
            'totals' => [
                'recipients' => (int) $t['recipients'],
                'sent' => (int) $t['sent'],
                'failed' => (int) $t['failed'],
                'skipped' => (int) $t['skipped'],
                'pending' => (int) $t['pending'],
                'delivered' => (int) $t['delivered'],
                'opened' => (int) $t['opened'],
                'clicked' => (int) $t['clicked'],
                'bounced' => (int) $t['bounced'],
                'complained' => (int) $t['complained'],
                'unsubscribed' => (int) $t['unsubscribed'],
            ],
            'rates' => [
                'open' => $rt['open'],
                'click' => $rt['click'],
                'bounce' => $rt['bounce'],
                'unsubscribe' => $rt['unsubscribe'],
            ],
            'series' => array_map(fn (array $d) => [
                'date' => $d['date'],
                'sent' => (int) $d['sent'],
                'opened' => (int) $d['opened'],
                'clicked' => (int) $d['clicked'],
                'bounced' => (int) $d['bounced'],
            ], $r['series']),
            'attribution' => [
                'leads' => (int) $r['attribution']['leads'],
                'deals' => (int) $r['attribution']['deals'],
            ],
        ];
    }
}
