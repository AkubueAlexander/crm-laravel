$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

foreach ($p in 'app\Models\Quote.php', 'app\Domain\Quotes\Actions\ReviseQuoteAction.php', 'app\Http\Resources\QuoteResource.php', 'app\Domain\Users\RoleCatalog.php', 'database\seeders\DevTenantSeeder.php', 'routes\api.php', 'database\migrations') {
    if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p" }
}

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path $Path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content -replace "`r`n", "`n"
    if (-not $c.EndsWith("`n")) { $c += "`n" }
    [System.IO.File]::WriteAllText($Path, $c, (New-Object System.Text.UTF8Encoding($false)))
}

function Count-Sub([string]$Text, [string]$Sub) {
    if ($Sub.Length -eq 0) { return 0 }
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $raw = [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)
    $crlf = $raw.Contains("`r`n")
    $t = $raw -replace "`r`n", "`n"
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor must occur exactly once in $Path : $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($Path, $t, (New-Object System.Text.UTF8Encoding($false)))
    return 'patched'
}

# ---------------- patches (collected, preflighted before anything is written) ----------------
$patches = New-Object System.Collections.Generic.List[object]

$permAnchor = "'quotes.manage',`n    ];"
$permRepl = "'quotes.manage',`n        'quotes.approve',`n    ];"
$patches.Add(@{ Path = 'app\Domain\Users\RoleCatalog.php'; Anchor = $permAnchor; Repl = $permRepl; Skip = 'quotes.approve' })
$patches.Add(@{ Path = 'database\seeders\DevTenantSeeder.php'; Anchor = $permAnchor; Repl = $permRepl; Skip = 'quotes.approve' })

$resAnchor = "'created_by' => `$this->created_by,"
$resRepl = (@(
    $resAnchor,
    "            'submitted_at' => `$this->submitted_at ? \Illuminate\Support\Carbon::parse(`$this->submitted_at)->toIso8601String() : null,",
    "            'decided_at' => `$this->decided_at ? \Illuminate\Support\Carbon::parse(`$this->decided_at)->toIso8601String() : null,",
    "            'decision_note' => `$this->decision_note,",
    "            'sent_at' => `$this->sent_at ? \Illuminate\Support\Carbon::parse(`$this->sent_at)->toIso8601String() : null,"
) -join "`n")
$patches.Add(@{ Path = 'app\Http\Resources\QuoteResource.php'; Anchor = $resAnchor; Repl = $resRepl; Skip = 'decision_note' })

$routeAnchor = "'revise'])->whereNumber('quote')->middleware('throttle:30,1');"
$routeRepl = (@(
    $routeAnchor,
    '',
    "        // 10.3a: the single status-transition endpoint (state-machine pattern 3.0): { to_status, lock_version, note? }.",
    "        Route::post('quotes/{quote}/transition', [\App\Http\Controllers\Api\Quotes\QuoteLifecycleController::class, 'transition'])->whereNumber('quote')->middleware('throttle:30,1');"
) -join "`n")
$patches.Add(@{ Path = 'routes\api.php'; Anchor = $routeAnchor; Repl = $routeRepl; Skip = 'QuoteLifecycleController' })

# PREFLIGHT: every anchor exactly once (or Skip present) against the ORIGINAL files.
foreach ($p in $patches) {
    $full = Join-Path $repo $p.Path
    $t = ([System.IO.File]::ReadAllText($full, [System.Text.Encoding]::UTF8)) -replace "`r`n", "`n"
    if ($t.Contains($p.Skip)) { continue }
    $c = Count-Sub $t $p.Anchor
    if ($c -ne 1) { throw "PREFLIGHT: anchor occurs $c times in $($p.Path): $($p.Anchor)" }
}

# ---------------- migration name: always AFTER the newest existing migration ----------------
$migDir = Join-Path $repo 'database\migrations'
$suffix = '_add_quote_workflow_columns.php'
$existing = @(Get-ChildItem $migDir -Filter '*.php' | ForEach-Object { $_.Name })
[System.Array]::Sort($existing, [System.StringComparer]::Ordinal)
$already = @($existing | Where-Object { $_.EndsWith($suffix) })
$migName = $null
if ($already.Count -eq 0) {
    $newest = $existing[$existing.Count - 1]
    $inv = [System.Globalization.CultureInfo]::InvariantCulture
    $newestStamp = [datetime]::ParseExact($newest.Substring(0, 17), 'yyyy_MM_dd_HHmmss', $inv)
    $stamp = Get-Date
    if ($stamp -le $newestStamp) { $stamp = $newestStamp.AddMinutes(1) }
    $migName = $stamp.ToString('yyyy_MM_dd_HHmmss', $inv) + $suffix
    foreach ($e in $existing) {
        if ([string]::CompareOrdinal($e, $migName) -gt 0) { throw "An existing migration sorts after $migName : $e" }
    }
}

# ---------------- files ----------------
if ($migName) {
    Write-NoBom ("database\migrations\" + $migName) @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10.3a: quote approval workflow. tenants.quote_approval_threshold: a quote whose total is at or below it is
 * auto-approved on submit (NULL = every quote needs a human approver). The quotes columns record who submitted
 * and who decided, plus the decision note (required on rejection) and the sent timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('quote_approval_threshold', 14, 2)->nullable();
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['submitted_at', 'decided_at', 'decision_note', 'sent_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('quote_approval_threshold');
        });
    }
};
'@
}

Write-NoBom 'app\Domain\Quotes\Exceptions\QuoteTransitionException.php' @'
<?php

namespace App\Domain\Quotes\Exceptions;

use App\Models\Quote;
use RuntimeException;

/**
 * One exception for every refused quote transition. `reason` is the stable machine code the controller puts in the
 * JSON body (same { message, errors, code } shape as the campaign/deal 409/422).
 */
final class QuoteTransitionException extends RuntimeException
{
    public const STALE = 'stale_quote';

    public const INVALID = 'invalid_transition';

    public const EMPTY_QUOTE = 'quote_empty';

    public const NOTE_REQUIRED = 'note_required';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Quote $quote = null,
    ) {
        parent::__construct($message);
    }

    /** 3.0 / 409: carries the locked, current server-side quote so the client can reconcile. */
    public static function stale(Quote $quote): self
    {
        return new self(self::STALE, 'This quote was updated by someone else - refresh to see the latest status.', $quote);
    }

    public static function invalid(string $from, string $to): self
    {
        return new self(self::INVALID, "A quote cannot move from {$from} to {$to}.");
    }

    public static function emptyQuote(): self
    {
        return new self(self::EMPTY_QUOTE, 'Add at least one line before submitting this quote for approval.');
    }

    public static function noteRequired(): self
    {
        return new self(self::NOTE_REQUIRED, 'Give a reason when rejecting a quote.');
    }

    public function isStale(): bool
    {
        return $this->reason === self::STALE;
    }
}
'@

Write-NoBom 'app\Domain\Quotes\Actions\TransitionQuoteAction.php' @'
<?php

namespace App\Domain\Quotes\Actions;

use App\Domain\Quotes\Exceptions\QuoteTransitionException;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.3a: the ONLY place a quote changes status (state-machine pattern 3.0). The client sends { to_status,
 * lock_version, note? }; the server decides everything else. lock_version is bumped on every transition and a
 * mismatch is a 409 carrying the current quote. Editing lines is a draft-only concern of SaveQuoteAction.
 *
 * Submit (draft -> pending_approval) auto-approves when the tenant's quote_approval_threshold covers the total;
 * the returned quote then already says "approved", so the client reconciles with the response.
 */
final class TransitionQuoteAction
{
    /** from => allowed targets. Anything not listed is terminal (accepted, declined, expired, rejected). */
    public const TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'draft'],
        'approved' => ['sent'],
        'sent' => ['accepted', 'declined', 'expired'],
    ];

    /** Permission required to move INTO a status; everything else needs quotes.manage. */
    private const APPROVER_TARGETS = ['approved', 'rejected'];

    public function execute(User $actor, int $quoteId, string $toStatus, int $lockVersion, ?string $note = null): Quote
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        if (! in_array($toStatus, Quote::STATUSES, true)) {
            throw ValidationException::withMessages(['to_status' => ['Unknown status.']]);
        }

        Gate::forUser($actor)->authorize(in_array($toStatus, self::APPROVER_TARGETS, true) ? 'quotes.approve' : 'quotes.manage');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($actor, $quoteId, $tenantId, $toStatus, $lockVersion, $note): Quote {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $quote = Quote::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($quoteId);

            if ($quote->lock_version !== $lockVersion) {
                throw QuoteTransitionException::stale($quote);
            }

            if (! in_array($toStatus, self::TRANSITIONS[$quote->status] ?? [], true)) {
                throw QuoteTransitionException::invalid($quote->status, $toStatus);
            }

            $now = now();
            $changes = ['status' => $toStatus, 'lock_version' => $quote->lock_version + 1];

            switch ($toStatus) {
                case 'pending_approval':
                    $hasLines = QuoteLine::query()->where('tenant_id', $tenantId)->where('quote_id', $quote->id)->exists();
                    if (! $hasLines) {
                        throw QuoteTransitionException::emptyQuote();
                    }

                    $changes += ['submitted_at' => $now, 'submitted_by' => $actor->id, 'decided_at' => null, 'decided_by' => null, 'decision_note' => null];

                    $threshold = DB::table('tenants')->where('id', $tenantId)->value('quote_approval_threshold');
                    if ($threshold !== null && (int) round(((float) $quote->total) * 100) <= (int) round(((float) $threshold) * 100)) {
                        $changes['status'] = 'approved';
                        $changes['decided_at'] = $now;
                        $changes['decision_note'] = 'Auto-approved: the total is within the approval threshold.';
                    }
                    break;

                case 'approved':
                    $changes += ['decided_at' => $now, 'decided_by' => $actor->id, 'decision_note' => $note];
                    break;

                case 'rejected':
                    if ($note === null) {
                        throw QuoteTransitionException::noteRequired();
                    }
                    $changes += ['decided_at' => $now, 'decided_by' => $actor->id, 'decision_note' => $note];
                    break;

                case 'draft': // withdrawn by the submitter
                    $changes += ['submitted_at' => null, 'submitted_by' => null, 'decided_at' => null, 'decided_by' => null, 'decision_note' => null];
                    break;

                case 'sent':
                    $changes['sent_at'] = $now;
                    break;
            }

            $quote->forceFill($changes)->save();

            return $quote;
        });
    }
}
'@

Write-NoBom 'app\Http\Requests\Quotes\TransitionQuoteRequest.php' @'
<?php

namespace App\Http\Requests\Quotes;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape check only. Which permission a given move needs (manage vs approve) and whether the move is legal is
 * decided by TransitionQuoteAction, which re-checks everything (2.4).
 */
class TransitionQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('quotes.manage') || $user->can('quotes.approve'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in(Quote::STATUSES)],
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
'@

Write-NoBom 'app\Http\Controllers\Api\Quotes\QuoteLifecycleController.php' @'
<?php

namespace App\Http\Controllers\Api\Quotes;

use App\Domain\Quotes\Actions\TransitionQuoteAction;
use App\Domain\Quotes\Exceptions\QuoteTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotes\TransitionQuoteRequest;
use App\Http\Resources\QuoteResource;
use Illuminate\Http\JsonResponse;

// {quote} is a plain id on purpose (no route-model binding): the Action looks it up with an explicit tenant predicate.
final class QuoteLifecycleController extends Controller
{
    public function transition(TransitionQuoteRequest $request, int $quote, TransitionQuoteAction $action): JsonResponse
    {
        $v = $request->validated();

        try {
            $updated = $action->execute($request->user(), $quote, $v['to_status'], (int) $v['lock_version'], $v['note'] ?? null);
        } catch (QuoteTransitionException $e) {
            if ($e->isStale() && $e->quote !== null) {
                // 409: the client's lock_version is behind. Return the current quote so it can reconcile.
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->reason,
                    'current' => (new QuoteResource($e->quote))->resolve($request),
                ], 409);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['to_status' => [$e->getMessage()]],
                'code' => $e->reason,
            ], 422);
        }

        return (new QuoteResource($updated))->response();
    }
}
'@

Write-NoBom 'tests\Feature\Quotes\QuoteWorkflowTest.php' @'
<?php

use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function quotwfSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function quotwfUser(Tenant $tenant, ?string $role = 'manager'): User
{
    quotwfSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** Default lines: A (standard tax) + B (zero tax) = total 71.50. Pass [] for an empty quote. */
function quotwfSeed(User $actor, string $title = 'Deal', ?array $lines = null): int
{
    quotwfSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => $title,
        'currency' => 'USD',
        'lines' => $lines ?? [
            ['name' => 'A', 'quantity' => 2, 'unit_price' => '10.00', 'tax_category' => 'standard'],
            ['name' => 'B', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero'],
        ],
    ])->id;
}

it('quotwf walks a quote from draft to accepted with the right people', function () {
    $tenant = Tenant::factory()->create();
    $sales = quotwfUser($tenant, 'sales');
    $manager = quotwfUser($tenant, 'manager');
    $id = quotwfSeed($sales);
    $go = fn (string $to, int $lock, array $extra = []) => $this->postJson("/api/v1/quotes/{$id}/transition", array_merge(['to_status' => $to, 'lock_version' => $lock], $extra));

    Sanctum::actingAs($sales);
    $submitted = $go('pending_approval', 0)
        ->assertOk()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('decided_at', null)
        ->assertJsonMissingPath('tenant_id');
    expect($submitted->json('submitted_at'))->not->toBeNull();

    $go('approved', 1)->assertForbidden(); // sales cannot approve
    $go('rejected', 1, ['note' => 'no'])->assertForbidden();

    Sanctum::actingAs($manager);
    $approved = $go('approved', 1)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('lock_version', 2);
    expect($approved->json('decided_at'))->not->toBeNull();

    Sanctum::actingAs($sales);
    $sent = $go('sent', 2)->assertOk()->assertJsonPath('status', 'sent')->assertJsonPath('lock_version', 3);
    expect($sent->json('sent_at'))->not->toBeNull();

    $go('accepted', 3)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('lock_version', 4);
    $go('sent', 4)->assertStatus(422)->assertJsonPath('code', 'invalid_transition'); // terminal
});

it('quotwf enforces permissions and tenant isolation', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = quotwfUser($a);
    $viewerA = quotwfUser($a, 'viewer');
    $mine = quotwfSeed($managerA);
    $theirs = quotwfSeed(quotwfUser($b));

    Sanctum::actingAs($viewerA);
    $this->postJson("/api/v1/quotes/{$mine}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0])->assertForbidden();

    Sanctum::actingAs($managerA);
    $this->postJson("/api/v1/quotes/{$theirs}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0])->assertNotFound();

    quotwfSwitch($b);
    expect(DB::table('quotes')->where('id', $theirs)->value('status'))->toBe('draft');
});

it('quotwf answers a stale lock_version with 409 and the current quote', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $id = quotwfSeed($manager);
    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/quotes/{$id}/transition", ['to_status' => 'pending_approval', 'lock_version' => 5])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_quote')
        ->assertJsonPath('current.id', $id)
        ->assertJsonPath('current.status', 'draft')
        ->assertJsonPath('current.lock_version', 0);

    expect(DB::table('quotes')->where('id', $id)->value('status'))->toBe('draft');
});

it('quotwf rejects only with a reason, and a rejected quote can be revised', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $id = quotwfSeed($manager);
    Sanctum::actingAs($manager);
    $go = fn (string $to, int $lock, array $extra = []) => $this->postJson("/api/v1/quotes/{$id}/transition", array_merge(['to_status' => $to, 'lock_version' => $lock], $extra));

    $go('pending_approval', 0)->assertOk();
    $go('rejected', 1)->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go('rejected', 1, ['note' => '   '])->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go('rejected', 1, ['note' => 'Discount too deep'])
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('decision_note', 'Discount too deep');

    // History stays; rework happens in a new version (10.2).
    $this->postJson("/api/v1/quotes/{$id}/revise")->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('status', 'draft');
});

it('quotwf refuses illegal moves, empty quotes and unknown statuses', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $id = quotwfSeed($manager);
    $empty = quotwfSeed($manager, 'Empty', []);
    Sanctum::actingAs($manager);
    $go = fn (int $q, string $to, int $lock) => $this->postJson("/api/v1/quotes/{$q}/transition", ['to_status' => $to, 'lock_version' => $lock]);

    $go($id, 'approved', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'sent', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'draft', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'bogus', 0)->assertStatus(422)->assertJsonValidationErrors('to_status');
    $go($empty, 'pending_approval', 0)->assertStatus(422)->assertJsonPath('code', 'quote_empty');

    // Submit, then withdraw: the quote is a draft again and the submission is forgotten.
    $go($id, 'pending_approval', 0)->assertOk();
    $go($id, 'sent', 1)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'draft', 1)->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('submitted_at', null)->assertJsonPath('lock_version', 2);
});

it('quotwf auto-approves a submission within the tenant threshold', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $small = quotwfSeed($manager, 'Small'); // total 71.50
    $big = quotwfSeed($manager, 'Big', [['name' => 'Big', 'quantity' => 1, 'unit_price' => '200.00', 'tax_category' => 'zero']]);
    Sanctum::actingAs($manager);
    $submit = fn (int $q) => $this->postJson("/api/v1/quotes/{$q}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0]);

    // No threshold: every quote needs a human.
    $submit($small)->assertOk()->assertJsonPath('status', 'pending_approval');

    DB::table('tenants')->where('id', $tenant->id)->update(['quote_approval_threshold' => '100.00']);

    $auto = $submit($big)->assertOk()->assertJsonPath('status', 'pending_approval'); // 200 > 100
    expect($auto->json('decision_note'))->toBeNull();

    $small2 = quotwfSeed($manager, 'Small 2');
    $approved = $submit($small2)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('lock_version', 1);
    expect($approved->json('decision_note'))->toContain('Auto-approved');
    expect($approved->json('decided_at'))->not->toBeNull();
});
'@

# ---------------- apply patches ----------------
$lines = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $lines.Add($m) }

foreach ($p in $patches) {
    $r = Patch-Once $p.Path $p.Anchor $p.Repl $p.Skip
    Say "$($p.Path): $r"
}
if ($migName) { Say "migration created: $migName" } else { Say 'migration: already present, not recreated' }

# ---------------- migrate + re-provision existing tenants (new permission) ----------------
$ErrorActionPreference = 'Continue'
Say 'Migrating...'
Say ((& php artisan migrate --no-interaction 2>&1 | Out-String).Trim())

$tmp = Join-Path $repo 'reprovision-tmp.php'
try {
    Write-NoBom $tmp @'
<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (App\Models\Tenant::query()->get() as $tenant) {
    App\Domain\Tenant\TenantContext::set($tenant);
    app(Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    App\Domain\Users\RoleCatalog::provision($tenant);
    App\Domain\Tenant\TenantContext::clear();
    echo "provisioned tenant {$tenant->id}\n";
}
'@
    Say ((& php $tmp 2>&1 | Out-String).Trim())
} finally {
    if (Test-Path $tmp) { Remove-Item $tmp -Force }
}

# ---------------- tests ----------------
Say 'Running quote tests...'
$q = (& php artisan test --no-ansi --filter='quotwf|quotact|quoteapi|quotver' 2>&1 | Out-String)
Say (($q -split "`r?`n" | Select-Object -Last 45) -join "`n")

Say 'Running the full suite (about 5 minutes, output appears at the end)...'
$full = (& php artisan test --no-ansi 2>&1 | Out-String)
Say (($full -split "`r?`n" | Select-Object -Last 25) -join "`n")

& php artisan queue:restart 2>&1 | Out-Null
$ErrorActionPreference = 'Stop'

Set-Clipboard -Value ($lines -join "`r`n")
Say 'Report copied'
Read-Host 'Press Enter to close'
Say 'NOT TOUCHED (referenced, not shown): CampaignLifecycleController, TransitionCampaignAction, CampaignLifecycleResource, Tenant model, TenantSettingsController (no threshold UI yet), frontend (10.3b).'
