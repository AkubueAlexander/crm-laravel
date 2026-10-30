$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found' }
Set-Location $repo
foreach ($p in @('routes\api.php', 'tests\Feature')) {
    if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p" }
}

$report = New-Object System.Collections.ArrayList
function Add-Report([string]$t) { [void]$report.Add($t) }
function Tail([string]$text, [int]$n) {
    $lines = $text -split "`r?`n"
    if ($lines.Count -le $n) { return ($lines -join "`n") }
    return (($lines | Select-Object -Last $n) -join "`n")
}
function Finish {
    $out = $report -join "`n"
    Write-Host $out
    Set-Clipboard -Value $out
    Write-Host 'Report copied'
    Read-Host 'Press Enter to close'
}
function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path $Path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $c, (New-Object System.Text.UTF8Encoding($false)))
}
function Read-Text([string]$p) { return [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8) }
function Count-Sub([string]$text, [string]$sub) {
    $n = 0; $i = 0
    while (($i = $text.IndexOf($sub, $i, [StringComparison]::Ordinal)) -ge 0) { $n++; $i += $sub.Length }
    return $n
}
function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $raw = Read-Text $Path
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not unique in ${Path}: $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, (New-Object System.Text.UTF8Encoding($false)))
    return 'patched'
}

# ------------------------------------------------------------ new files
$test = @'
<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

// 14.4: the limiter must not leak between tests (or runs), so every test pins it to the array store.
function rlimIsolate(): void
{
    config(['cache.limiter' => 'array', 'logging.default' => 'null']);
}

function rlimActor(): User
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('throttles login attempts with a structured 429 and Retry-After', function () {
    rlimIsolate();

    $limited = null;
    for ($i = 1; $i <= 12; $i++) {
        $response = $this->postJson('/api/v1/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);
        if ($response->status() === 429) {
            $limited = $response;
            break;
        }
    }

    expect($limited)->not->toBeNull();
    $limited->assertJson(['code' => 'http_429', 'errors' => []])->assertHeader('Retry-After');
});

it('throttles public lead form submissions at 10 per minute', function () {
    rlimIsolate();
    $uuid = '00000000-0000-4000-8000-000000000000';

    for ($i = 1; $i <= 10; $i++) {
        expect($this->postJson("/api/public/forms/{$uuid}", [])->status())->not->toBe(429);
    }

    $this->postJson("/api/public/forms/{$uuid}", [])
        ->assertStatus(429)
        ->assertJson(['code' => 'http_429']);
});

it('throttles invitation acceptance at 10 per minute', function () {
    rlimIsolate();
    $token = str_repeat('a', 48);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->postJson("/api/v1/invitations/{$token}/accept", [])->status())->not->toBe(429);
    }

    $this->postJson("/api/v1/invitations/{$token}/accept", [])
        ->assertStatus(429)
        ->assertJson(['code' => 'http_429']);
});

it('throttles client error reports at 20 per minute per user', function () {
    rlimIsolate();
    Sanctum::actingAs(rlimActor());

    for ($i = 1; $i <= 20; $i++) {
        $this->postJson('/api/v1/client-errors', ['message' => 'boom'])->assertNoContent();
    }

    $this->postJson('/api/v1/client-errors', ['message' => 'boom'])
        ->assertStatus(429)
        ->assertJson(['code' => 'http_429']);
});
'@

$doc = @'
# 14.4 Rate limits

Every unauthenticated endpoint is throttled; authenticated endpoints are covered selectively (see "Accepted gap").
Throttles are `throttle:<attempts>,<minutes>`. Unauthenticated requests are keyed by client IP, authenticated
ones by user. Behind a proxy or load balancer configure trusted proxies (see 16.5) or every visitor shares one IP.

## Public / unauthenticated

| Endpoint | Limit | Notes |
|---|---|---|
| `POST /api/v1/login` | 10/min per IP | Added in 14.4. |
| `GET /api/v1/invitations/{token}` | 30/min per IP | Uniform 410 for every failure. |
| `POST /api/v1/invitations/{token}/accept` | 10/min per IP | Token is the credential. |
| `GET /unsubscribe/{token}`, `POST /unsubscribe/{token}` | 30/min per IP | Signed token, no session, no CSRF. |
| `GET /api/public/forms/{uuid}` | 60/min per IP | 9.8. |
| `POST /api/public/forms/{uuid}` | 10/min per IP | Plus CAPTCHA (Turnstile) and honeypot inside the action. |
| `GET /f/{uuid}` | 120/min per IP | Serves the standalone form entry. |
| `GET /e/o/{token}`, `GET /e/c/{token}`, `POST /webhooks/email-events` | 600/min per IP | 9.5. The webhook is signed; it needs a provider adapter. |

## Authenticated infrastructure

| Endpoint | Limit | Notes |
|---|---|---|
| `POST /api/v1/broadcasting/auth` | 120/min per user | Added in 14.4: every private-channel subscribe (and every re-subscribe after a Reverb reconnect) hits it. |
| `POST /api/v1/client-errors` | 20/min per user | 14.1. |
| `GET /api/v1/search` | 120/min per user | |
| Notifications | 120/min per user | |

## Reverb reconnect storms

The HTTP side is the broadcasting auth throttle above. The WebSocket server itself is limited by Reverb's own
`rate_limiting` / `max_connections` options in `config/reverb.php` (per app). Set them before production traffic;
that file was not changed in 14.4.

## Accepted gap

Most authenticated create/update/delete routes carry no throttle (campaigns, segments, quotes, products, contacts,
accounts, leads, activities, automations, lead forms, email templates, leave requests, user role changes, pipeline
settings, deal transitions). They require a signed-in tenant user and a permission, run inside tenant scope, and
the expensive ones (imports, exports, previews, sync, PDFs, transitions on money documents) are already throttled.
Revisit with a named limiter (`RateLimiter::for`) if abuse by a legitimate account appears. Do not add a second
`throttle:` middleware to a route that already has one: unnamed throttles share a counter per user/IP.
'@

# ------------------------------------------------------------ patches (preflight first)
$apiPath = Join-Path $repo 'routes\api.php'
$loginRep = @'
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1') // 14.4 login
'@.TrimEnd("`r", "`n")
$bcastRep = "Broadcast::routes(['middleware' => ['throttle:120,1']]); // 14.4 broadcast auth"

$patches = New-Object System.Collections.ArrayList
[void]$patches.Add(@{ Path = $apiPath; Anchor = "Route::post('/login', [AuthenticatedSessionController::class, 'store'])"; Replacement = $loginRep; Skip = '// 14.4 login' })
[void]$patches.Add(@{ Path = $apiPath; Anchor = "Broadcast::routes(['middleware' => []]);"; Replacement = $bcastRep; Skip = '// 14.4 broadcast auth' })

foreach ($p in $patches) {
    $t = (Read-Text $p.Path).Replace("`r`n", "`n")
    if ($t.Contains($p.Skip)) { continue }
    if ((Count-Sub $t $p.Anchor) -ne 1) { throw "PREFLIGHT: anchor not unique in $($p.Path): $($p.Anchor)" }
}

Write-NoBom 'tests\Feature\Security\RateLimitTest.php' $test
Write-NoBom 'docs\14.4-rate-limits.md' $doc
foreach ($p in $patches) {
    Add-Report ('patch api.php: ' + (Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip))
}
Add-Report 'Wrote: tests/Feature/Security/RateLimitTest.php, docs/14.4-rate-limits.md; patched routes/api.php (login + broadcasting auth throttles)'

# ------------------------------------------------------------ tests
$ErrorActionPreference = 'Continue'
php artisan route:clear 2>&1 | Out-Null
php artisan config:clear 2>&1 | Out-Null
$t1 = php artisan test tests/Feature/Security 2>&1 | Out-String
$t1Code = $LASTEXITCODE
if ($t1Code -ne 0) {
    $ErrorActionPreference = 'Stop'
    Add-Report ("Security tests FAILED:`n" + (Tail $t1 70))
    Add-Report 'Full suite skipped.'
} else {
    Add-Report ("Security tests OK:`n" + (Tail $t1 6))
    Write-Host 'Running the full suite (about 5-9 minutes, output appears at the end)...'
    $t2 = php artisan test 2>&1 | Out-String
    $t2Code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($t2Code -eq 0) { Add-Report ("Full suite OK:`n" + (Tail $t2 6)) }
    else { Add-Report ("Full suite FAILED:`n" + (Tail $t2 70)) }
}
$ErrorActionPreference = 'Continue'
php artisan queue:restart 2>&1 | Out-Null
$ErrorActionPreference = 'Stop'

Add-Report 'NOT TOUCHED (referenced, not shown): AuthenticatedSessionController (may already rate-limit internally), config/reverb.php (set rate_limiting/max_connections before production), trusted-proxy config, AppServiceProvider (no named limiters added)'
Finish
