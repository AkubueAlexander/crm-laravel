
$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found in repo' }
Set-Location $repo

$enc = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n")
    if (-not $c.EndsWith("`n")) { $c += "`n" }
    [System.IO.File]::WriteAllText($Path, $c, $enc)
}
function Read-Utf8([string]$p) { [System.IO.File]::ReadAllText($p, $enc) }
function Count-Sub([string]$s, [string]$sub) {
    $n = 0; $i = 0
    while (($i = $s.IndexOf($sub, $i, [StringComparison]::Ordinal)) -ge 0) { $n++; $i += $sub.Length }
    return $n
}
function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $raw = Read-Utf8 $Path
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    $a = $Anchor.Replace("`r`n", "`n")
    $r = $Replacement.Replace("`r`n", "`n")
    if ($t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $a) -ne 1) { throw "Anchor not exactly once in $Path : $a" }
    $t = $t.Replace($a, $r)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, $enc)
    return 'patched'
}

$apiPhp = Join-Path $repo 'routes\api.php'
$routerTsx = Join-Path $repo 'frontend\src\router.tsx'
$campaignsPage = Join-Path $repo 'frontend\src\features\marketing\components\CampaignsPage.tsx'
foreach ($p in @($apiPhp, $routerTsx, $campaignsPage)) { if (-not (Test-Path $p)) { throw "Missing $p" } }

# ---------------- patches (collected, preflighted, applied after the files) ----------------
$patches = New-Object System.Collections.Generic.List[object]

$patches.Add(@{ Path = $apiPhp; Skip = 'CampaignReportController'
Anchor = @'
require __DIR__ . '/campaign_lifecycle.php';
'@
Replacement = @'
require __DIR__ . '/campaign_lifecycle.php';
        // 9.10: read-only campaign report (totals, rates, daily series, utm attribution).
        Route::get('campaigns/{campaign}/report', [\App\Http\Controllers\Api\Marketing\CampaignReportController::class, 'show'])
            ->whereNumber('campaign')
            ->middleware('throttle:60,1');
'@ })

$patches.Add(@{ Path = $routerTsx; Skip = "import { CampaignDashboard }"
Anchor = @'
import { LeadFormsPage } from '@/features/marketing/components/LeadFormsPage';
'@
Replacement = @'
import { LeadFormsPage } from '@/features/marketing/components/LeadFormsPage';
import { CampaignDashboard } from '@/features/marketing/components/CampaignDashboard';
'@ })

$patches.Add(@{ Path = $routerTsx; Skip = 'const campaignDashboardRoute'
Anchor = @'
const routeTree = rootRoute.addChildren([
'@
Replacement = @'
// 9.10: campaign dashboard.
const campaignDashboardRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/marketing/campaigns/$campaignId',
    component: CampaignDashboard,
});

const routeTree = rootRoute.addChildren([
'@ })

$patches.Add(@{ Path = $routerTsx; Skip = 'leadFormsRoute, campaignDashboardRoute'
Anchor = @'
...automationRoutes, leadFormsRoute]),
'@
Replacement = @'
...automationRoutes, leadFormsRoute, campaignDashboardRoute]),
'@ })

$patches.Add(@{ Path = $campaignsPage; Skip = 'import { Link, useNavigate'
Anchor = @'
import { useNavigate, useSearch } from '@tanstack/react-router';
'@
Replacement = @'
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
'@ })

$patches.Add(@{ Path = $campaignsPage; Skip = '/marketing/campaigns/$campaignId'
Anchor = @'
<div className="font-medium">{info.getValue()}</div>
'@
Replacement = @'
<div className="font-medium">
                            {info.row.original.status === 'draft' ? (
                                info.getValue()
                            ) : (
                                <Link
                                    to="/marketing/campaigns/$campaignId"
                                    params={{ campaignId: String(info.row.original.id) }}
                                    className="underline-offset-2 hover:underline"
                                >
                                    {info.getValue()}
                                </Link>
                            )}
                        </div>
'@ })

# PREFLIGHT against the original files
foreach ($p in $patches) {
    $t = (Read-Utf8 $p.Path).Replace("`r`n", "`n")
    if ($t.Contains($p.Skip)) { continue }
    $n = Count-Sub $t ($p.Anchor.Replace("`r`n", "`n"))
    if ($n -ne 1) { throw "PREFLIGHT: anchor occurs $n times in $($p.Path): $($p.Anchor)" }
}

# ---------------- new files ----------------
Write-NoBom 'app\Domain\Marketing\CampaignReportBuilder.php' @'
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
'@

Write-NoBom 'app\Http\Controllers\Api\Marketing\CampaignReportController.php' @'
<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Domain\Marketing\CampaignReportBuilder;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignReportResource;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

// 9.10: {campaign} is a plain id (no route-model binding): the lookup carries an explicit tenant predicate,
// so another tenant's campaign is a 404.
final class CampaignReportController extends Controller
{
    public function show(Request $request, int $campaign, CampaignReportBuilder $builder): CampaignReportResource
    {
        Gate::authorize('marketing.view');

        $model = Campaign::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->findOrFail($campaign);

        return new CampaignReportResource($builder->build($model));
    }
}
'@

Write-NoBom 'app\Http\Resources\CampaignReportResource.php' @'
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
'@

Write-NoBom 'tests\Feature\CampaignDashboardTest.php' @'
<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function cmpdashUser(Tenant $tenant, bool $allow = true): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($allow) {
        Gate::define('marketing.view', fn () => true);
    }

    return $user;
}

function cmpdashCampaign(Tenant $tenant, string $name = 'Spring Sale'): int
{
    return DB::table('campaigns')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmpdashRecipient(Tenant $tenant, int $campaignId, string $email, array $over = []): void
{
    DB::table('campaign_recipients')->insert(array_merge([
        'tenant_id' => $tenant->id,
        'campaign_id' => $campaignId,
        'email' => $email,
        'email_normalized' => $email,
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ], $over));
}

it('cmpdash reports totals, rates and the daily series from recipient rows', function () {
    $tenant = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $id = cmpdashCampaign($tenant);

    cmpdashRecipient($tenant, $id, 'a@x.test', ['sent_at' => '2026-10-20 10:00:00', 'opened_at' => '2026-10-20 11:00:00', 'clicked_at' => '2026-10-21 09:00:00']);
    cmpdashRecipient($tenant, $id, 'b@x.test', ['sent_at' => '2026-10-20 10:00:00', 'opened_at' => '2026-10-21 08:00:00']);
    cmpdashRecipient($tenant, $id, 'c@x.test', ['sent_at' => '2026-10-20 10:00:00', 'bounced_at' => '2026-10-20 12:00:00']);
    cmpdashRecipient($tenant, $id, 'd@x.test', ['status' => 'failed']);
    DB::table('email_suppressions')->insert([
        'tenant_id' => $tenant->id,
        'email_normalized' => 'a@x.test',
        'reason' => 'unsubscribed',
        'campaign_id' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $json = $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertOk()->json('data');

    expect($json['totals'])->toEqual([
        'recipients' => 4, 'sent' => 3, 'failed' => 1, 'skipped' => 0, 'pending' => 0,
        'delivered' => 2, 'opened' => 2, 'clicked' => 1, 'bounced' => 1, 'complained' => 0, 'unsubscribed' => 1,
    ]);
    expect($json['rates'])->toEqual(['open' => 100.0, 'click' => 50.0, 'bounce' => 33.3, 'unsubscribe' => 50.0]);
    expect($json['series'])->toEqual([
        ['date' => '2026-10-20', 'sent' => 3, 'opened' => 1, 'clicked' => 0, 'bounced' => 1],
        ['date' => '2026-10-21', 'sent' => 0, 'opened' => 1, 'clicked' => 1, 'bounced' => 0],
    ]);
    expect($json['campaign']['name'])->toBe('Spring Sale');
});

it('cmpdash has null rates and an empty series when nothing was sent', function () {
    $tenant = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $id = cmpdashCampaign($tenant);

    $json = $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertOk()->json('data');

    expect($json['rates'])->toEqual(['open' => null, 'click' => null, 'bounce' => null, 'unsubscribe' => null]);
    expect($json['series'])->toEqual([]);
});

it('cmpdash attributes only this tenants leads by utm_campaign', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $id = cmpdashCampaign($tenant, 'Spring Sale');

    TenantContext::set($tenant);
    $mine = Lead::factory()->create();
    $unrelated = Lead::factory()->create();
    TenantContext::set($other);
    $theirs = Lead::factory()->create();

    DB::table('leads')->where('id', $mine->id)->update(['utm_campaign' => 'spring sale']);
    DB::table('leads')->where('id', $unrelated->id)->update(['utm_campaign' => 'other']);
    DB::table('leads')->where('id', $theirs->id)->update(['utm_campaign' => 'Spring Sale']);

    $json = $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertOk()->json('data');

    expect($json['attribution']['leads'])->toBe(1);
    expect($json['attribution']['deals'])->toBe(0);
});

it('cmpdash returns 404 for another tenants campaign', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = cmpdashUser($tenant);
    $theirs = cmpdashCampaign($other);

    $this->actingAs($user)->getJson("/api/v1/campaigns/{$theirs}/report")->assertNotFound();
});

it('cmpdash is forbidden without marketing.view', function () {
    $tenant = Tenant::factory()->create();
    $user = cmpdashUser($tenant, false);
    $id = cmpdashCampaign($tenant);

    $this->actingAs($user)->getJson("/api/v1/campaigns/{$id}/report")->assertForbidden();
});
'@

Write-NoBom 'frontend\src\features\marketing\schemas\campaignReport.ts' @'
import { z } from 'zod';
import { CAMPAIGN_STATUSES } from './campaign';

const rate = z.number().nullable();

// Mirrors CampaignReportResource (9.10).
export const campaignReportSchema = z.object({
    data: z.object({
        campaign: z.object({
            id: z.number(),
            name: z.string(),
            status: z.enum(CAMPAIGN_STATUSES),
            recipients_count: z.number(),
            started_at: z.string().nullable(),
            completed_at: z.string().nullable(),
            sent_at: z.string().nullable(),
        }),
        totals: z.object({
            recipients: z.number(),
            sent: z.number(),
            failed: z.number(),
            skipped: z.number(),
            pending: z.number(),
            delivered: z.number(),
            opened: z.number(),
            clicked: z.number(),
            bounced: z.number(),
            complained: z.number(),
            unsubscribed: z.number(),
        }),
        rates: z.object({ open: rate, click: rate, bounce: rate, unsubscribe: rate }),
        series: z.array(
            z.object({
                date: z.string(),
                sent: z.number(),
                opened: z.number(),
                clicked: z.number(),
                bounced: z.number(),
            }),
        ),
        attribution: z.object({ leads: z.number(), deals: z.number() }),
    }),
});

export type CampaignReport = z.infer<typeof campaignReportSchema>['data'];
'@

Write-NoBom 'frontend\src\features\marketing\api\useCampaignReport.ts' @'
import { useRef } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { campaignReportSchema, type CampaignReport } from '../schemas/campaignReport';
import { campaignProgressSchema } from '../schemas/campaignProgress';
import { CAMPAIGN_PROGRESS_EVENT } from './useCampaignProgress';

export function campaignReportKey(tenantId: unknown, campaignId: number) {
    return ['campaign-report', tenantId, campaignId] as const;
}

const THROTTLE_MS = 2_000;

// 9.10: the report is server state (TanStack Query). While the campaign is sending it is refreshed by the
// 9.4d progress event (throttled; the event carries counters only, so we refetch rather than patch) with a 10 s
// refetch interval as a fallback. Opens/clicks after the send arrive on window focus / revisit (not pushed live yet).
export function useCampaignReport(campaignId: number) {
    const { tenant } = useTenant();
    const queryClient = useQueryClient();
    const lastInvalidate = useRef(0);
    const key = campaignReportKey(tenant?.id, campaignId);

    const query = useQuery({
        queryKey: key,
        queryFn: async (): Promise<CampaignReport> => {
            const { data } = await apiClient.get(`/campaigns/${campaignId}/report`);
            return campaignReportSchema.parse(data).data;
        },
        enabled: !!tenant?.id && campaignId > 0,
        refetchInterval: (q) => (q.state.data?.campaign.status === 'sending' ? 10_000 : false),
    });

    useEchoChannel(tenant?.id ? `tenant.${tenant.id}.campaigns` : '', CAMPAIGN_PROGRESS_EVENT, (raw) => {
        const parsed = campaignProgressSchema.safeParse(raw);
        if (!parsed.success || parsed.data.id !== campaignId) return;
        const now = Date.now();
        if (now - lastInvalidate.current < THROTTLE_MS) return;
        lastInvalidate.current = now;
        void queryClient.invalidateQueries({ queryKey: key });
    });

    return query;
}
'@

Write-NoBom 'frontend\src\features\marketing\components\CampaignDashboard.tsx' @'
import { Link, useParams } from '@tanstack/react-router';
import { Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useCampaignReport } from '../api/useCampaignReport';
import { MarketingTabs } from './MarketingTabs';

const DASH = '\u2014';

function pct(rate: number | null): string {
    return rate === null ? DASH : `${rate}%`;
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
            <p className="text-xs text-[var(--color-ink-muted)]">{label}</p>
            <p className="mt-1 text-xl font-medium text-[var(--color-ink)]">{value}</p>
            {sub && <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">{sub}</p>}
        </div>
    );
}

export function CampaignDashboard() {
    const allowed = usePermission('marketing.view');
    const params = useParams({ strict: false }) as { campaignId?: string };
    const campaignId = Number(params.campaignId);
    const valid = Number.isInteger(campaignId) && campaignId > 0;
    const { data, isPending, isFetching, isError, error } = useCampaignReport(valid ? campaignId : 0);

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to marketing.</p>;
    }

    const n = (v: number) => v.toLocaleString();

    return (
        <div>
            <MarketingTabs />

            <div className="mb-4 flex items-center justify-between">
                <div>
                    <Link to="/marketing" className="text-xs text-[var(--color-ink-muted)] hover:underline">
                        &larr; Campaigns
                    </Link>
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">
                        {data ? data.campaign.name : 'Campaign report'}
                        {data && (
                            <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium capitalize text-gray-700">
                                {data.campaign.status}
                            </span>
                        )}
                    </h1>
                </div>
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
            </div>

            {!valid && <p className="text-sm text-red-700">Invalid campaign.</p>}

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load the campaign report.")}
                </p>
            )}

            {valid && isPending && <p className="text-sm text-gray-500">Loading...</p>}

            {data && (
                <>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Stat label="Recipients" value={n(data.totals.recipients)} />
                        <Stat
                            label="Sent"
                            value={n(data.totals.sent)}
                            sub={data.totals.failed > 0 ? `${n(data.totals.failed)} failed` : undefined}
                        />
                        <Stat label="Delivered" value={n(data.totals.delivered)} />
                        <Stat label="Unsubscribed" value={n(data.totals.unsubscribed)} sub={pct(data.rates.unsubscribe)} />
                        <Stat label="Opened" value={n(data.totals.opened)} sub={`${pct(data.rates.open)} of delivered`} />
                        <Stat label="Clicked" value={n(data.totals.clicked)} sub={`${pct(data.rates.click)} of delivered`} />
                        <Stat label="Bounced" value={n(data.totals.bounced)} sub={`${pct(data.rates.bounce)} of sent`} />
                        <Stat label="Complaints" value={n(data.totals.complained)} />
                    </div>

                    <section className="mt-6 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
                        <h2 className="mb-3 text-sm font-medium text-[var(--color-ink)]">Activity by day</h2>
                        {data.series.length === 0 ? (
                            <p className="text-sm text-[var(--color-ink-muted)]">No activity yet.</p>
                        ) : (
                            <div style={{ width: '100%', height: 280 }}>
                                <ResponsiveContainer>
                                    <ComposedChart data={data.series}>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="date" tickFormatter={(d: string) => d.slice(5)} fontSize={12} />
                                        <YAxis allowDecimals={false} fontSize={12} />
                                        <Tooltip />
                                        <Legend />
                                        <Bar dataKey="sent" name="Sent" fill="#cbd5e1" />
                                        <Line type="monotone" dataKey="opened" name="Opened" stroke="#2563eb" strokeWidth={2} dot={false} />
                                        <Line type="monotone" dataKey="clicked" name="Clicked" stroke="#16a34a" strokeWidth={2} dot={false} />
                                        <Line type="monotone" dataKey="bounced" name="Bounced" stroke="#dc2626" strokeWidth={2} dot={false} />
                                    </ComposedChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </section>

                    <section className="mt-6">
                        <h2 className="mb-2 text-sm font-medium text-[var(--color-ink)]">Attribution</h2>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <Stat label="Leads" value={n(data.attribution.leads)} />
                            <Stat label="Deals" value={n(data.attribution.deals)} />
                        </div>
                        <p className="mt-2 text-xs text-[var(--color-ink-muted)]">
                            Matched on utm_campaign equal to this campaign&apos;s name (or its slug), case-insensitive.
                        </p>
                    </section>
                </>
            )}
        </div>
    );
}
'@

# ---------------- apply patches ----------------
foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Write-Host "$res : $($p.Path)"
}

# ---------------- verify ----------------
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $report.Add($m) }

$ErrorActionPreference = 'Continue'

Push-Location (Join-Path $repo 'frontend')
$tsc = (& npx.cmd tsc --noEmit 2>&1 | Out-String)
$tscExit = $LASTEXITCODE
Pop-Location
Say "tsc exit: $tscExit"
if ($tscExit -ne 0) { Say $tsc }

$one = (& php artisan test --no-ansi tests/Feature/CampaignDashboardTest.php 2>&1 | Out-String)
Say "cmpdash tests exit: $LASTEXITCODE"
Say $one

$full = (& php artisan test --no-ansi 2>&1 | Out-String)
Say "full suite exit: $LASTEXITCODE (expected 445 passed = 440 + 5 cmpdash)"
$tail = ($full -split "`n" | Select-Object -Last 40) -join "`n"
Say $tail

$ErrorActionPreference = 'Stop'
Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): MarketingTabs.tsx, usePermission, apiErrorMessage, Lead/Deal models and factories, Pest.php, TenantContext, the campaign progress broadcast event.'
