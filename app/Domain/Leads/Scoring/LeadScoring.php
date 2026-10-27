<?php

declare(strict_types=1);

namespace App\Domain\Leads\Scoring;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 9.7: the lead score. A transparent, rule-based 0-100 number: every point comes from a named factor that is
 * stored next to the score, so the lead detail can show WHY a lead has it. compute() is pure (no DB, no
 * framework); rescore() and friends are plain DB access with explicit tenant predicates, safe from actions,
 * commands and queue jobs alike.
 *
 * Signals: how complete the lead is, where it came from, where it is in the funnel, and (matched by email inside
 * the tenant) whether it opened, clicked or bounced on campaign emails.
 */
final class LeadScoring
{
    public const SOURCE_POINTS = ['referral' => 20, 'event' => 15, 'web' => 10, 'cold-outreach' => 0];

    public const OTHER_SOURCE_POINTS = 5;

    public const STATUS_POINTS = ['new' => 0, 'contacted' => 10, 'qualified' => 25, 'converted' => 25];

    /** Engagement newer than this (hours) makes leads:rescore pick a lead up. */
    public const ENGAGEMENT_WINDOW_HOURS = 2;

    /**
     * @param  array{email?: mixed, phone?: mixed, company?: mixed, last_name?: mixed, source?: mixed, status?: mixed}  $lead
     * @param  array{opened?: bool, clicked?: bool, bad?: bool}  $engagement
     * @return array{score: int, factors: list<array{key: string, label: string, points: int}>}
     */
    public static function compute(array $lead, array $engagement = []): array
    {
        $status = (string) ($lead['status'] ?? 'new');

        if ($status === 'disqualified') {
            return ['score' => 0, 'factors' => [['key' => 'disqualified', 'label' => 'Disqualified leads are not scored', 'points' => 0]]];
        }

        $factors = [];
        $add = static function (string $key, string $label, int $points) use (&$factors): void {
            if ($points !== 0) {
                $factors[] = ['key' => $key, 'label' => $label, 'points' => $points];
            }
        };

        $email = trim((string) ($lead['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $add('email', 'Has a valid email', 10);
        }
        if (trim((string) ($lead['phone'] ?? '')) !== '') {
            $add('phone', 'Has a phone number', 5);
        }
        if (trim((string) ($lead['company'] ?? '')) !== '') {
            $add('company', 'Has a company', 10);
        }
        if (trim((string) ($lead['last_name'] ?? '')) !== '') {
            $add('last_name', 'Has a last name', 5);
        }

        $source = mb_strtolower(trim((string) ($lead['source'] ?? '')));
        if ($source !== '') {
            $add('source', 'Source: '.mb_substr($source, 0, 40), self::SOURCE_POINTS[$source] ?? self::OTHER_SOURCE_POINTS);
        }

        $add('status', 'Status: '.$status, self::STATUS_POINTS[$status] ?? 0);

        if (! empty($engagement['opened'])) {
            $add('opened', 'Opened a campaign email', 10);
        }
        if (! empty($engagement['clicked'])) {
            $add('clicked', 'Clicked a campaign link', 15);
        }
        if (! empty($engagement['bad'])) {
            $add('bounced', 'Email bounced or was marked as spam', -20);
        }

        $sum = array_sum(array_column($factors, 'points'));

        return ['score' => max(0, min(100, $sum)), 'factors' => $factors];
    }

    /**
     * Recomputes and stores the score of one lead. Returns the RAW stored values (score_factors as a JSON
     * string), or null when the lead does not exist in this tenant (or is deleted).
     *
     * @return array{lead_score: int, score_factors: string, score_updated_at: string}|null
     */
    public static function rescore(int $tenantId, int $leadId): ?array
    {
        $lead = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->where('id', $leadId)
            ->whereNull('deleted_at')
            ->first(['id', 'email', 'phone', 'company', 'last_name', 'source', 'status']);

        if ($lead === null) {
            return null;
        }

        $result = self::compute((array) $lead, self::engagementFor($tenantId, (string) $lead->email));

        $values = [
            'lead_score' => $result['score'],
            'score_factors' => json_encode($result['factors']),
            'score_updated_at' => now()->toDateTimeString(),
        ];

        // Plain update: no model events, and updated_at (the user-facing "last changed") is left alone.
        DB::table('leads')->where('tenant_id', $tenantId)->where('id', $leadId)->update($values);

        return $values;
    }

    /** Rescores the lead and refreshes the in-memory model, so the response of the calling Action carries it. */
    public static function applyTo(Lead $lead): void
    {
        try {
            $values = self::rescore((int) $lead->tenant_id, (int) $lead->id);
        } catch (Throwable $e) {
            // Scoring must never break creating, editing or converting a lead.
            report($e);

            return;
        }

        if ($values !== null) {
            $lead->setRawAttributes(array_merge($lead->getAttributes(), $values), true);
        }
    }

    /**
     * What the scheduler calls: leads never scored, plus leads whose email had campaign engagement lately
     * (or every lead with $all). Returns how many were rescored.
     */
    public static function rescoreDue(bool $all = false): int
    {
        $query = DB::table('leads')->whereNull('deleted_at');

        if (! $all) {
            $cutoff = now()->subHours(self::ENGAGEMENT_WINDOW_HOURS)->toDateTimeString();

            $query->where(function ($w) use ($cutoff): void {
                $w->whereNull('score_updated_at')->orWhereExists(function ($e) use ($cutoff): void {
                    $e->select(DB::raw('1'))
                        ->from('campaign_recipients as r')
                        ->whereColumn('r.tenant_id', 'leads.tenant_id')
                        ->whereRaw('r.email_normalized = lower(trim(leads.email))')
                        ->where(function ($when) use ($cutoff): void {
                            $when->where('r.opened_at', '>=', $cutoff)
                                ->orWhere('r.clicked_at', '>=', $cutoff)
                                ->orWhere('r.bounced_at', '>=', $cutoff)
                                ->orWhere('r.complained_at', '>=', $cutoff);
                        });
                });
            });
        }

        $count = 0;

        $query->select(['id', 'tenant_id'])->chunkById(500, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                if (self::rescore((int) $row->tenant_id, (int) $row->id) !== null) {
                    $count++;
                }
            }
        }, 'id');

        return $count;
    }

    /** @return array{opened: bool, clicked: bool, bad: bool} */
    private static function engagementFor(int $tenantId, string $email): array
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '') {
            return ['opened' => false, 'clicked' => false, 'bad' => false];
        }

        $row = DB::table('campaign_recipients')
            ->where('tenant_id', $tenantId)
            ->where('email_normalized', $normalized)
            ->selectRaw('bool_or(opened_at is not null) as opened, bool_or(clicked_at is not null) as clicked, bool_or(bounced_at is not null or complained_at is not null) as bad')
            ->first();

        return [
            'opened' => (bool) ($row->opened ?? false),
            'clicked' => (bool) ($row->clicked ?? false),
            'bad' => (bool) ($row->bad ?? false),
        ];
    }
}