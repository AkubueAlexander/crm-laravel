$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found in repo' }
Set-Location $repo
$enc = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $c = $Content.Replace("`r`n", "`n")
    if (-not $c.EndsWith("`n")) { $c += "`n" }
    [System.IO.File]::WriteAllText($Path, $c, $enc)
}

# 1. test: read the unwrapped body
$testPath = Join-Path $repo 'tests\Feature\CampaignDashboardTest.php'
$t = [System.IO.File]::ReadAllText($testPath, $enc)
$before = $t
$t = $t.Replace("->json('data');", '->json();')
if ($t -eq $before) { throw 'test file: nothing to replace' }
[System.IO.File]::WriteAllText($testPath, $t, $enc)

# 2. schema: unwrapped
Write-NoBom 'frontend\src\features\marketing\schemas\campaignReport.ts' @'
import { z } from 'zod';
import { CAMPAIGN_STATUSES } from './campaign';

const rate = z.number().nullable();

// Mirrors CampaignReportResource (9.10). The app serves single resources unwrapped (no "data" envelope).
export const campaignReportSchema = z.object({
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
});

export type CampaignReport = z.infer<typeof campaignReportSchema>;
'@

# 3. hook: parse without .data
$hook = Join-Path $repo 'frontend\src\features\marketing\api\useCampaignReport.ts'
$h = [System.IO.File]::ReadAllText($hook, $enc)
$old = 'return campaignReportSchema.parse(data).data;'
if (([regex]::Matches($h, [regex]::Escape($old))).Count -ne 1) { throw 'hook anchor not exactly once' }
$h = $h.Replace($old, 'return campaignReportSchema.parse(data);')
[System.IO.File]::WriteAllText($hook, $h, $enc)

$ErrorActionPreference = 'Continue'
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $report.Add($m) }

Push-Location (Join-Path $repo 'frontend')
$tsc = (& npx.cmd tsc --noEmit 2>&1 | Out-String)
$tscExit = $LASTEXITCODE
Pop-Location
Say "tsc exit: $tscExit"
if ($tscExit -ne 0) { Say $tsc }

$one = (& php artisan test --no-ansi tests/Feature/CampaignDashboardTest.php 2>&1 | Out-String)
Say "cmpdash tests exit: $LASTEXITCODE"
Say $one

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): the rest of the suite (run php artisan test once to confirm 445).'
