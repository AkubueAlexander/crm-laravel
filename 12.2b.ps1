$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

$utf8 = New-Object System.Text.UTF8Encoding($false)
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $report.Add($m) }

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $text = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $text, $utf8)
}

foreach ($p in @('app\Domain\Accounting\Sync\LedgerSyncRunner.php', 'app\Jobs\RunLedgerSyncJob.php', 'app\Models\LedgerSyncRun.php', 'routes\accounting.php', 'app\Http\Resources\LedgerAccountResource.php')) {
    if (-not (Test-Path (Join-Path $repo $p))) { throw "Prerequisite missing (run 12.2a first): $p" }
}

$files = New-Object System.Collections.ArrayList

# ---------------------------------------------------------------- actions
$saveSettings = @'
<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** 12.2: chooses the tenant's ledger sync provider (one settings row per tenant, upserted). Needs accounting.manage. */
final class SaveLedgerSyncSettingsAction
{
    public function execute(User $actor, string $provider): string
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        $provider = trim($provider);
        if (! in_array($provider, LedgerSyncProviders::ids(), true)) {
            throw ValidationException::withMessages(['provider' => ['Choose a sync provider.']]);
        }

        DB::table('ledger_sync_settings')->upsert(
            [['tenant_id' => $tenantId, 'provider' => $provider, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]],
            ['tenant_id'],
            ['provider', 'updated_by', 'updated_at'],
        );

        return $provider;
    }
}
'@
[void]$files.Add(@{ Path = 'app\Domain\Accounting\Actions\SaveLedgerSyncSettingsAction.php'; Content = $saveSettings })

$requestSync = @'
<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Jobs\RunLedgerSyncJob;
use App\Models\LedgerSyncRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * 12.2: "Sync now". Creates a queued run for the tenant's current provider and dispatches the job AFTER the outermost
 * commit. One active run per tenant: a friendly pre-check plus the partial unique index as the race guard. A queued or
 * running run older than 30 minutes is considered dead (worker crash) and is closed as failed first, so it cannot block
 * syncing forever. Needs accounting.manage.
 */
final class RequestLedgerSyncAction
{
    private const STALE_MINUTES = 30;

    public function execute(User $actor): LedgerSyncRun
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        $provider = LedgerSyncProviders::current($tenantId);

        try {
            $run = DB::transaction(function () use ($actor, $tenantId, $provider): LedgerSyncRun {
                LedgerSyncRun::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', LedgerSyncRun::ACTIVE)
                    ->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
                    ->update([
                        'status' => 'failed',
                        'error' => 'Timed out: the sync did not finish and was closed.',
                        'finished_at' => now(),
                    ]);

                $active = LedgerSyncRun::query()->where('tenant_id', $tenantId)->whereIn('status', LedgerSyncRun::ACTIVE)->exists();
                if ($active) {
                    throw ValidationException::withMessages(['sync' => ['A sync is already in progress.']]);
                }

                $run = new LedgerSyncRun();
                $run->forceFill([
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'status' => 'queued',
                    'requested_by' => $actor->id,
                ])->save();

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sync' => ['A sync is already in progress.']]);
        }

        $runId = $run->id;
        DB::afterCommit(function () use ($tenantId, $runId): void {
            try {
                RunLedgerSyncJob::dispatch($tenantId, $runId);
            } catch (Throwable $e) {
                report($e);
                DB::table('ledger_sync_runs')->where('tenant_id', $tenantId)->where('id', $runId)->where('status', 'queued')->update([
                    'status' => 'failed',
                    'error' => 'Could not queue the sync. Please try again.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $run;
    }
}
'@
[void]$files.Add(@{ Path = 'app\Domain\Accounting\Actions\RequestLedgerSyncAction.php'; Content = $requestSync })

# ---------------------------------------------------------------- resource + controller
$resource = @'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** 12.2: explicit whitelist of a sync run's fields. */
class LedgerSyncRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error' => $this->error,
            'accounts_synced' => $this->accounts_synced,
            'entries_synced' => $this->entries_synced,
            'to_entry_id' => $this->to_entry_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
'@
[void]$files.Add(@{ Path = 'app\Http\Resources\LedgerSyncRunResource.php'; Content = $resource })

$controller = @'
<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\Actions\RequestLedgerSyncAction;
use App\Domain\Accounting\Actions\SaveLedgerSyncSettingsAction;
use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerSyncRunResource;
use App\Models\LedgerSyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

// 12.2: ledger sync to an external books-of-record system. accounting.view to read, accounting.manage to change/run.
// Tenant always comes from the session. Sync runs are not a status workflow the user edits: nothing here is optimistic.
final class LedgerSyncController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('accounting.view');

        return response()->json($this->status((int) $request->user()->tenant_id));
    }

    public function saveSettings(Request $request, SaveLedgerSyncSettingsAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        // Shape check only: the Action re-checks permission and that the provider is known (2.4).
        $v = $request->validate(['provider' => ['required', 'string', 'max:20']]);
        $action->execute($request->user(), $v['provider']);

        return response()->json($this->status((int) $request->user()->tenant_id));
    }

    public function run(Request $request, RequestLedgerSyncAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $run = $action->execute($request->user());

        return (new LedgerSyncRunResource($run))->response()->setStatusCode(202);
    }

    public function runs(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('accounting.view');

        $v = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $rows = LedgerSyncRun::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->orderByDesc('id')
            ->paginate($v['per_page'] ?? 10)
            ->withQueryString();

        return LedgerSyncRunResource::collection($rows);
    }

    /** @return array<string, mixed> */
    private function status(int $tenantId): array
    {
        $provider = LedgerSyncProviders::current($tenantId);

        $base = fn () => LedgerSyncRun::query()->where('tenant_id', $tenantId);
        $last = $base()->orderByDesc('id')->first();
        $lastOk = $base()->where('provider', $provider)->where('status', 'succeeded')->orderByDesc('id')->first();
        $active = $base()->whereIn('status', LedgerSyncRun::ACTIVE)->orderByDesc('id')->first();

        return [
            'provider' => $provider,
            'providers' => LedgerSyncProviders::catalog(),
            'last_synced_at' => $lastOk?->finished_at?->toIso8601String(),
            'last_run' => $last ? (new LedgerSyncRunResource($last))->resolve() : null,
            'active_run' => $active ? (new LedgerSyncRunResource($active))->resolve() : null,
        ];
    }
}
'@
[void]$files.Add(@{ Path = 'app\Http\Controllers\Api\Accounting\LedgerSyncController.php'; Content = $controller })

# ---------------------------------------------------------------- routes (whole file: it was shown in this chat)
$routes = @'
<?php

use App\Http\Controllers\Api\Accounting\JournalEntryController;
use App\Http\Controllers\Api\Accounting\LedgerAccountController;
use App\Http\Controllers\Api\Accounting\LedgerReportController;
use App\Http\Controllers\Api\Accounting\LedgerSyncController;
use Illuminate\Support\Facades\Route;

// 12.0b: general ledger. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
// Ids are plain ints on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
// Permissions: accounting.view to read (including reports), accounting.manage to change.

// Chart of accounts. Literal paths before {account}.
Route::get('accounting/accounts', [LedgerAccountController::class, 'index']);

Route::post('accounting/accounts/defaults', [LedgerAccountController::class, 'defaults'])->middleware('throttle:10,1');

Route::post('accounting/accounts', [LedgerAccountController::class, 'store'])->middleware('throttle:30,1');

Route::put('accounting/accounts/{account}', [LedgerAccountController::class, 'update'])->whereNumber('account')->middleware('throttle:30,1');

// Journal: append-only. Post a balanced entry, or reverse one (a new entry with swapped lines).
Route::get('accounting/journal-entries', [JournalEntryController::class, 'index']);

Route::post('accounting/journal-entries', [JournalEntryController::class, 'store'])->middleware('throttle:60,1');

Route::get('accounting/journal-entries/{entry}', [JournalEntryController::class, 'show'])->whereNumber('entry');

Route::post('accounting/journal-entries/{entry}/reverse', [JournalEntryController::class, 'reverse'])->whereNumber('entry')->middleware('throttle:30,1');

// Operational reports (read-only aggregates over the journal; NOT the books of record).
Route::get('accounting/reports/trial-balance', [LedgerReportController::class, 'trialBalance'])->middleware('throttle:60,1');

Route::get('accounting/reports/income-statement', [LedgerReportController::class, 'incomeStatement'])->middleware('throttle:60,1');

Route::get('accounting/reports/balance-sheet', [LedgerReportController::class, 'balanceSheet'])->middleware('throttle:60,1');

Route::get('accounting/reports/ledger', [LedgerReportController::class, 'ledger'])->middleware('throttle:60,1');

// 12.2: ledger sync to QuickBooks/Xero (provider choice, "Sync now", run history). Literal paths only.
Route::get('accounting/sync', [LedgerSyncController::class, 'show']);

Route::put('accounting/sync/settings', [LedgerSyncController::class, 'saveSettings'])->middleware('throttle:30,1');

Route::post('accounting/sync/run', [LedgerSyncController::class, 'run'])->middleware('throttle:10,1');

Route::get('accounting/sync/runs', [LedgerSyncController::class, 'runs']);
'@
[void]$files.Add(@{ Path = 'routes\accounting.php'; Content = $routes })

# ---------------------------------------------------------------- tests
$actionsTest = @'
<?php

use App\Domain\Accounting\Actions\RequestLedgerSyncAction;
use App\Domain\Accounting\Actions\SaveLedgerSyncSettingsAction;
use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Jobs\RunLedgerSyncJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function lsyncactUser(Tenant $tenant, string $role = 'manager'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('lsyncact saves the provider per tenant and only for accounting managers', function () {
    $b = Tenant::factory()->create();
    lsyncactUser($b);

    $a = Tenant::factory()->create();
    $mgr = lsyncactUser($a);
    $viewer = lsyncactUser($a, 'viewer');
    $sales = lsyncactUser($a, 'sales');
    $save = app(SaveLedgerSyncSettingsAction::class);

    expect(LedgerSyncProviders::current($a->id))->toBe('log');
    expect($save->execute($mgr, 'quickbooks'))->toBe('quickbooks');
    expect(LedgerSyncProviders::current($a->id))->toBe('quickbooks')->and(LedgerSyncProviders::current($b->id))->toBe('log');

    $save->execute($mgr, 'log');
    expect(LedgerSyncProviders::current($a->id))->toBe('log')->and(DB::table('ledger_sync_settings')->where('tenant_id', $a->id)->count())->toBe(1);

    expect(fn () => $save->execute($mgr, 'sage'))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($viewer, 'xero'))->toThrow(AuthorizationException::class);
    expect(fn () => $save->execute($sales, 'xero'))->toThrow(AuthorizationException::class);
});

it('lsyncact queues one sync at a time, per tenant, and closes stale runs', function () {
    Queue::fake();

    $b = Tenant::factory()->create();
    $mb = lsyncactUser($b);
    $theirs = app(RequestLedgerSyncAction::class)->execute($mb); // another tenant's active run must not block tenant a

    $a = Tenant::factory()->create();
    $mgr = lsyncactUser($a);
    $viewer = lsyncactUser($a, 'viewer');
    $sales = lsyncactUser($a, 'sales');
    $request = app(RequestLedgerSyncAction::class);

    app(SaveLedgerSyncSettingsAction::class)->execute($mgr, 'xero');
    $run = $request->execute($mgr);
    expect($run->status)->toBe('queued')->and($run->provider)->toBe('xero')->and($run->requested_by)->toBe($mgr->id)->and($run->tenant_id)->toBe($a->id);
    Queue::assertPushed(RunLedgerSyncJob::class, fn ($j) => $j->tenantId === $a->id && $j->runId === $run->id);

    expect(fn () => $request->execute($mgr))->toThrow(ValidationException::class); // already active
    expect(fn () => $request->execute($viewer))->toThrow(AuthorizationException::class);
    expect(fn () => $request->execute($sales))->toThrow(AuthorizationException::class);
    expect(DB::table('ledger_sync_runs')->where('tenant_id', $a->id)->count())->toBe(1);

    // A dead run (older than 30 minutes) is closed as failed and no longer blocks.
    DB::table('ledger_sync_runs')->where('id', $run->id)->update(['created_at' => now()->subMinutes(31)]);
    $next = $request->execute($mgr);
    expect($next->id)->not->toBe($run->id);
    $old = DB::table('ledger_sync_runs')->where('id', $run->id)->first();
    expect($old->status)->toBe('failed')->and($old->error)->toContain('Timed out')->and($old->finished_at)->not->toBeNull();

    // The other tenant's run was never touched.
    expect(DB::table('ledger_sync_runs')->where('id', $theirs->id)->value('status'))->toBe('queued');
});

it('lsyncact runs end to end through the job and then allows the next sync', function () {
    Queue::fake();
    config(['logging.default' => 'null']);

    $a = Tenant::factory()->create();
    $mgr = lsyncactUser($a);
    $request = app(RequestLedgerSyncAction::class);

    $run = $request->execute($mgr);
    (new RunLedgerSyncJob($a->id, $run->id))->handle();
    TenantContext::set($a);
    setPermissionsTeamId($a->id);

    expect(DB::table('ledger_sync_runs')->where('id', $run->id)->value('status'))->toBe('succeeded');
    expect($request->execute($mgr)->id)->not->toBe($run->id);
});
'@
[void]$files.Add(@{ Path = 'tests\Feature\Accounting\LedgerSyncActionsTest.php'; Content = $actionsTest })

$apiTest = @'
<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Jobs\RunLedgerSyncJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function lsyncapiUser(Tenant $tenant, string $role = 'manager'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('lsyncapi requires authentication and accounting permissions', function () {
    $this->getJson('/api/v1/accounting/sync')->assertUnauthorized();

    $a = Tenant::factory()->create();
    $mgr = lsyncapiUser($a);
    $viewer = lsyncapiUser($a, 'viewer');
    $sales = lsyncapiUser($a, 'sales');
    Queue::fake();

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/accounting/sync')->assertForbidden();
    $this->getJson('/api/v1/accounting/sync/runs')->assertForbidden();

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/accounting/sync')->assertOk();
    $this->getJson('/api/v1/accounting/sync/runs')->assertOk();
    $this->putJson('/api/v1/accounting/sync/settings', ['provider' => 'xero'])->assertForbidden();
    $this->postJson('/api/v1/accounting/sync/run')->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->getJson('/api/v1/accounting/sync')->assertOk();
});

it('lsyncapi shows the status, saves the provider and validates it', function () {
    $a = Tenant::factory()->create();
    $mgr = lsyncapiUser($a);
    Sanctum::actingAs($mgr);

    $this->getJson('/api/v1/accounting/sync')
        ->assertOk()
        ->assertJsonPath('provider', 'log')
        ->assertJsonPath('last_synced_at', null)
        ->assertJsonPath('last_run', null)
        ->assertJsonPath('active_run', null)
        ->assertJsonCount(3, 'providers');

    $this->putJson('/api/v1/accounting/sync/settings', ['provider' => 'quickbooks'])->assertOk()->assertJsonPath('provider', 'quickbooks');
    $this->getJson('/api/v1/accounting/sync')->assertJsonPath('provider', 'quickbooks');

    $this->putJson('/api/v1/accounting/sync/settings', ['provider' => 'sage'])->assertUnprocessable()->assertJsonValidationErrors(['provider']);
    $this->putJson('/api/v1/accounting/sync/settings', [])->assertUnprocessable()->assertJsonValidationErrors(['provider']);
});

it('lsyncapi runs a sync once at a time and lists only this tenants runs', function () {
    Queue::fake();
    config(['logging.default' => 'null']);

    $b = Tenant::factory()->create();
    $mb = lsyncapiUser($b);
    Sanctum::actingAs($mb);
    $theirRun = $this->postJson('/api/v1/accounting/sync/run')->assertStatus(202)->json('id');

    $a = Tenant::factory()->create();
    $mgr = lsyncapiUser($a);
    Sanctum::actingAs($mgr);

    $created = $this->postJson('/api/v1/accounting/sync/run')->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonPath('provider', 'log');
    $runId = $created->json('id');
    Queue::assertPushed(RunLedgerSyncJob::class, fn ($j) => $j->tenantId === $a->id && $j->runId === $runId);

    $this->postJson('/api/v1/accounting/sync/run')->assertUnprocessable()->assertJsonValidationErrors(['sync']);
    $this->getJson('/api/v1/accounting/sync')->assertJsonPath('active_run.id', $runId)->assertJsonPath('last_synced_at', null);

    // The job finishes the run; the status now has a last_synced_at and no active run.
    (new RunLedgerSyncJob($a->id, $runId))->handle();

    $this->getJson('/api/v1/accounting/sync')
        ->assertOk()
        ->assertJsonPath('active_run', null)
        ->assertJsonPath('last_run.status', 'succeeded');
    expect($this->getJson('/api/v1/accounting/sync')->json('last_synced_at'))->not->toBeNull();

    $list = $this->getJson('/api/v1/accounting/sync/runs')->assertOk();
    expect($list->json('meta.total'))->toBe(1)->and($list->json('data.0.id'))->toBe($runId)->and($list->json('data.0.status'))->toBe('succeeded');
    expect(DB::table('ledger_sync_runs')->where('id', $theirRun)->value('tenant_id'))->toBe($b->id);

    // The next sync is allowed again.
    $this->postJson('/api/v1/accounting/sync/run')->assertStatus(202);
});
'@
[void]$files.Add(@{ Path = 'tests\Feature\Accounting\LedgerSyncApiTest.php'; Content = $apiTest })

# ---------------------------------------------------------------- write
foreach ($f in $files) { Write-NoBom $f.Path $f.Content; Say "wrote $($f.Path)" }

# ---------------------------------------------------------------- tests
function Tail([string]$text, [int]$n) { return (($text -split "`r?`n") | Select-Object -Last $n) -join "`r`n" }

$ErrorActionPreference = 'Continue'
& php artisan route:clear 2>&1 | Out-Null

Say 'Running tests/Feature/Accounting...'
$t1 = & php artisan test tests/Feature/Accounting 2>&1 | Out-String
$t1Exit = $LASTEXITCODE
if ($t1Exit -ne 0) {
    Say "Accounting tests FAILED (exit $t1Exit)"
    Say (Tail $t1 70)
} else {
    Say 'Accounting tests: passed'
    Say (Tail $t1 6)
    Write-Host 'Running the full suite (about 5-9 minutes, output appears at the end)...'
    $t2 = & php artisan test 2>&1 | Out-String
    $t2Exit = $LASTEXITCODE
    if ($t2Exit -ne 0) { Say "Full suite FAILED (exit $t2Exit)"; Say (Tail $t2 70) } else { Say 'Full suite: passed'; Say (Tail $t2 6) }
}

& php artisan queue:restart 2>&1 | Out-Null
$ErrorActionPreference = 'Stop'

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): routes/api.php (already requires accounting.php), RoleCatalog, DevTenantSeeder (no new permissions), JournalEntryController, LedgerAccountResource.'
