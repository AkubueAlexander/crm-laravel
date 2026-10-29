$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

$utf8 = New-Object System.Text.UTF8Encoding($false)
function Read-Text([string]$p) { [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8) }
function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $t = $Content.Replace("`r`n", "`n")
    if (-not $t.EndsWith("`n")) { $t += "`n" }
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
}
function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}
function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $raw = Read-Text $Path
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not found exactly once in $Path : $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
    return 'patched'
}

$apiPath = Join-Path $repo 'routes\api.php'
foreach ($need in @($apiPath, (Join-Path $repo 'app\Domain\Accounting\Actions\PostJournalEntryAction.php'), (Join-Path $repo 'app\Models\JournalEntry.php'))) {
    if (-not (Test-Path $need)) { throw "Missing prerequisite: $need (run 12.0a first)" }
}

# ---------------------------------------------------------------- files
$routes = @'
<?php

use App\Http\Controllers\Api\Accounting\JournalEntryController;
use App\Http\Controllers\Api\Accounting\LedgerAccountController;
use App\Http\Controllers\Api\Accounting\LedgerReportController;
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
'@

$provision = @'
<?php

namespace App\Domain\Accounting\Actions;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * 12.0b: loads a small starter chart of accounts. Idempotent: only codes the tenant does not have yet are created,
 * and existing accounts are never touched. Goes through SaveLedgerAccountAction so validation is the same. Needs
 * accounting.manage. Returns how many accounts were created.
 */
final class ProvisionDefaultChartAction
{
    public const DEFAULTS = [
        ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'],
        ['code' => '1100', 'name' => 'Accounts receivable', 'type' => 'asset'],
        ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset'],
        ['code' => '2000', 'name' => 'Accounts payable', 'type' => 'liability'],
        ['code' => '2200', 'name' => 'Sales tax payable', 'type' => 'liability'],
        ['code' => '3000', 'name' => "Owner's equity", 'type' => 'equity'],
        ['code' => '4000', 'name' => 'Sales revenue', 'type' => 'revenue'],
        ['code' => '5000', 'name' => 'Cost of goods sold', 'type' => 'expense'],
        ['code' => '6000', 'name' => 'Operating expenses', 'type' => 'expense'],
    ];

    public function execute(User $actor): int
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        return DB::transaction(function () use ($actor, $tenantId): int {
            $have = LedgerAccount::query()->where('tenant_id', $tenantId)->pluck('code')->all();
            $created = 0;

            foreach (self::DEFAULTS as $account) {
                if (in_array($account['code'], $have, true)) {
                    continue;
                }
                app(SaveLedgerAccountAction::class)->execute($actor, null, $account);
                $created++;
            }

            return $created;
        });
    }
}
'@

$reports = @'
<?php

namespace App\Domain\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 12.0b: read-only aggregates over the append-only journal. Every query carries an explicit tenant predicate on both
 * the lines and the entries. All arithmetic is integer cents. These are OPERATIONAL reports (the books of record live
 * in QuickBooks/Xero): there are no closing entries, so the balance sheet shows "current earnings" as the running
 * revenue-minus-expense total up to the date.
 */
final class LedgerReports
{
    /** @return array<string, mixed> */
    public function trialBalance(int $tenantId, string $asOf): array
    {
        $rows = [];
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($this->balances($tenantId, null, $asOf) as $r) {
            if ($r['debit'] === 0 && $r['credit'] === 0) {
                continue;
            }

            $net = $r['debit'] - $r['credit'];
            $debit = max($net, 0);
            $credit = max(-$net, 0);
            $debitTotal += $debit;
            $creditTotal += $credit;

            $rows[] = $this->identify($r['account']) + ['debit' => $this->money($debit), 'credit' => $this->money($credit)];
        }

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => ['debit' => $this->money($debitTotal), 'credit' => $this->money($creditTotal), 'balanced' => $debitTotal === $creditTotal],
        ];
    }

    /** @return array<string, mixed> */
    public function incomeStatement(int $tenantId, string $from, string $to): array
    {
        $revenue = [];
        $expenses = [];
        $revenueTotal = 0;
        $expenseTotal = 0;

        foreach ($this->balances($tenantId, $from, $to) as $r) {
            $type = $r['account']->type;

            if ($type === 'revenue') {
                $amount = $r['credit'] - $r['debit'];
                if ($amount !== 0) {
                    $revenue[] = $this->identify($r['account']) + ['amount' => $this->money($amount)];
                    $revenueTotal += $amount;
                }
            } elseif ($type === 'expense') {
                $amount = $r['debit'] - $r['credit'];
                if ($amount !== 0) {
                    $expenses[] = $this->identify($r['account']) + ['amount' => $this->money($amount)];
                    $expenseTotal += $amount;
                }
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => $this->money($revenueTotal),
            'total_expenses' => $this->money($expenseTotal),
            'net_income' => $this->money($revenueTotal - $expenseTotal),
        ];
    }

    /** @return array<string, mixed> */
    public function balanceSheet(int $tenantId, string $asOf): array
    {
        $assets = [];
        $liabilities = [];
        $equity = [];
        $assetTotal = 0;
        $liabilityTotal = 0;
        $equityTotal = 0;
        $revenueTotal = 0;
        $expenseTotal = 0;

        foreach ($this->balances($tenantId, null, $asOf) as $r) {
            $type = $r['account']->type;
            $debitNormal = $r['debit'] - $r['credit'];

            if ($type === 'asset' && $debitNormal !== 0) {
                $assets[] = $this->identify($r['account']) + ['amount' => $this->money($debitNormal)];
                $assetTotal += $debitNormal;
            } elseif ($type === 'liability' && $debitNormal !== 0) {
                $liabilities[] = $this->identify($r['account']) + ['amount' => $this->money(-$debitNormal)];
                $liabilityTotal += -$debitNormal;
            } elseif ($type === 'equity' && $debitNormal !== 0) {
                $equity[] = $this->identify($r['account']) + ['amount' => $this->money(-$debitNormal)];
                $equityTotal += -$debitNormal;
            } elseif ($type === 'revenue') {
                $revenueTotal += -$debitNormal;
            } elseif ($type === 'expense') {
                $expenseTotal += $debitNormal;
            }
        }

        $earnings = $revenueTotal - $expenseTotal;
        $equityWithEarnings = $equityTotal + $earnings;

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings' => $this->money($earnings),
            'total_assets' => $this->money($assetTotal),
            'total_liabilities' => $this->money($liabilityTotal),
            'total_equity' => $this->money($equityWithEarnings),
            'total_liabilities_and_equity' => $this->money($liabilityTotal + $equityWithEarnings),
            'balanced' => $assetTotal === $liabilityTotal + $equityWithEarnings,
        ];
    }

    /**
     * One account's lines with a running balance on the account's normal side (debit-normal: debits minus credits;
     * credit-normal: the reverse). The running total is computed over ALL the account's lines in date order, then
     * the date range and the page are cut from it, so a page always shows the true balance.
     *
     * @return array{account: LedgerAccount, opening: string, paginator: LengthAwarePaginator}
     */
    public function accountLedger(int $tenantId, int $accountId, ?string $from, ?string $to, int $perPage, int $page): array
    {
        // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
        $account = LedgerAccount::query()->where('tenant_id', $tenantId)->findOrFail($accountId);
        $sign = in_array($account->type, LedgerAccount::DEBIT_NORMAL, true) ? 1 : -1;

        $opening = 0;
        if ($from !== null) {
            $row = $this->lines($tenantId)
                ->where('l.ledger_account_id', $account->id)
                ->where('e.entry_date', '<', $from)
                ->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')
                ->first();
            $opening = $sign * ($this->cents($row->d) - $this->cents($row->c));
        }

        $inner = $this->lines($tenantId)
            ->where('l.ledger_account_id', $account->id)
            ->selectRaw('e.id as entry_id, e.entry_number, e.entry_date, e.memo, l.description, l.debit, l.credit, l.id as line_id, sum(l.debit - l.credit) over (order by e.entry_date, e.id, l.id) as running');

        $paginator = DB::query()
            ->fromSub($inner, 't')
            ->when($from !== null, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('entry_date', '<=', $to))
            ->orderBy('entry_date')
            ->orderBy('entry_id')
            ->orderBy('line_id')
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection($paginator->getCollection()->map(fn ($r) => [
            'entry_id' => (int) $r->entry_id,
            'entry_number' => $r->entry_number,
            'entry_date' => substr((string) $r->entry_date, 0, 10),
            'memo' => $r->memo,
            'description' => $r->description,
            'debit' => $this->money($this->cents($r->debit)),
            'credit' => $this->money($this->cents($r->credit)),
            'balance' => $this->money($sign * $this->cents($r->running)),
        ])->values());

        return ['account' => $account, 'opening' => $this->money($opening), 'paginator' => $paginator];
    }

    /**
     * Every account of the tenant (chart order) with its debit and credit totals over the date range (inclusive).
     *
     * @return list<array{account: LedgerAccount, debit: int, credit: int}>
     */
    private function balances(int $tenantId, ?string $from, ?string $to): array
    {
        $sums = $this->lines($tenantId)
            ->when($from !== null, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->groupBy('l.ledger_account_id')
            ->selectRaw('l.ledger_account_id as account_id, sum(l.debit) as d, sum(l.credit) as c')
            ->get()
            ->keyBy('account_id');

        return LedgerAccount::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->get()
            ->map(fn (LedgerAccount $a) => [
                'account' => $a,
                'debit' => $this->cents($sums->get($a->id)->d ?? 0),
                'credit' => $this->cents($sums->get($a->id)->c ?? 0),
            ])
            ->all();
    }

    /** Lines joined to their entries, both pinned to the tenant. */
    private function lines(int $tenantId)
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.tenant_id', $tenantId)
            ->where('e.tenant_id', $tenantId);
    }

    /** @return array{account_id: int, code: string, name: string, type: string} */
    private function identify(LedgerAccount $a): array
    {
        return ['account_id' => (int) $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type];
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
'@

$accountResource = @'
<?php

namespace App\Http\Resources;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chart-of-accounts entry as the SPA sees it (12.0b). Explicit whitelist: no tenant id.
 *
 * @mixin LedgerAccount
 */
class LedgerAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
'@

$entryResource = @'
<?php

namespace App\Http\Resources;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A journal entry as the SPA sees it (12.0b). Explicit whitelist: no tenant id. Money is a decimal string.
 * "total" is the entry's total debits (= total credits). "reversed_by_entry_id" is attached by the controller.
 * Lines appear only when loaded (show / store / reverse); the list omits them.
 *
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $total = $this->resource->getAttribute('total_debit');
        if ($total === null && $this->relationLoaded('lines')) {
            $total = $this->lines->sum(fn ($l) => (float) $l->debit);
        }

        return [
            'id' => $this->id,
            'entry_seq' => $this->entry_seq,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date?->format('Y-m-d'),
            'memo' => $this->memo,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'reverses_entry_id' => $this->reverses_entry_id,
            'reversed_by_entry_id' => $this->resource->getAttribute('reversed_by_entry_id'),
            'posted_by' => $this->posted_by,
            'total' => $total === null ? null : number_format((float) $total, 2, '.', ''),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'ledger_account_id' => $l->ledger_account_id,
                'account_code' => $l->relationLoaded('account') ? $l->account?->code : null,
                'account_name' => $l->relationLoaded('account') ? $l->account?->name : null,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'description' => $l->description,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
'@

$accountController = @'
<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\Actions\ProvisionDefaultChartAction;
use App\Domain\Accounting\Actions\SaveLedgerAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerAccountResource;
use App\Models\LedgerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// {account} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class LedgerAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('accounting.view');

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(LedgerAccount::TYPES)],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = trim((string) ($v['q'] ?? ''));

        $rows = LedgerAccount::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like);
                });
            })
            ->when($v['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when(isset($v['active']), fn ($query) => $query->where('is_active', filter_var($v['active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('code')
            ->orderBy('id')
            ->paginate($v['per_page'] ?? 100)
            ->withQueryString();

        return LedgerAccountResource::collection($rows);
    }

    public function store(Request $request, SaveLedgerAccountAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $account = $action->execute($request->user(), null, $this->validated($request));

        return (new LedgerAccountResource($account))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $account, SaveLedgerAccountAction $action): LedgerAccountResource
    {
        Gate::authorize('accounting.manage');

        return new LedgerAccountResource($action->execute($request->user(), $account, $this->validated($request)));
    }

    /** Loads the starter chart of accounts. Idempotent: returns how many accounts were created. */
    public function defaults(Request $request, ProvisionDefaultChartAction $action): JsonResponse
    {
        return response()->json(['created' => $action->execute($request->user())]);
    }

    /** Shape check only. SaveLedgerAccountAction re-checks permission, formats, uniqueness and frozen fields (2.4). @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', Rule::in(LedgerAccount::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
'@

$entryController = @'
<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Domain\Accounting\Actions\ReverseJournalEntryAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

// {entry} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class JournalEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('accounting.view');

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));

        $rows = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->withSum('lines as total_debit', 'debit')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('entry_number', 'ilike', $like)->orWhere('memo', 'ilike', $like);
                });
            })
            ->when($v['account_id'] ?? null, function ($query, $accountId) use ($tenantId) {
                $query->whereIn('id', JournalLine::query()->where('tenant_id', $tenantId)->where('ledger_account_id', (int) $accountId)->select('journal_entry_id'));
            })
            ->when($v['from'] ?? null, fn ($query, $from) => $query->where('entry_date', '>=', $from))
            ->when($v['to'] ?? null, fn ($query, $to) => $query->where('entry_date', '<=', $to))
            ->orderByDesc('entry_date')
            ->orderByDesc('id') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        $this->attachReversals($rows->getCollection(), $tenantId);

        return JournalEntryResource::collection($rows);
    }

    public function show(Request $request, int $entry): JournalEntryResource
    {
        Gate::authorize('accounting.view');

        $tenantId = (int) $request->user()->tenant_id;
        $found = JournalEntry::query()->where('tenant_id', $tenantId)->with('lines.account')->findOrFail($entry);
        $this->attachReversals(collect([$found]), $tenantId);

        return new JournalEntryResource($found);
    }

    public function store(Request $request, PostJournalEntryAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $v = $request->validate([
            'entry_date' => ['required', 'string', 'max:10'],
            'memo' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:'.PostJournalEntryAction::MAX_LINES],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['nullable'],
            'lines.*.credit' => ['nullable'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_map(fn (array $l) => [
            'account_id' => $l['account_id'],
            'debit' => is_scalar($l['debit'] ?? null) ? (string) $l['debit'] : '',
            'credit' => is_scalar($l['credit'] ?? null) ? (string) $l['credit'] : '',
            'description' => $l['description'] ?? null,
        ], array_values($v['lines']));

        $entry = $action->execute($request->user(), (string) $v['entry_date'], $v['memo'] ?? null, $lines);

        return (new JournalEntryResource($entry->load('lines.account')))->response()->setStatusCode(201);
    }

    public function reverse(Request $request, int $entry, ReverseJournalEntryAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $v = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'entry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $reversal = $action->execute($request->user(), $entry, (string) $v['reason'], $v['entry_date'] ?? null);

        return (new JournalEntryResource($reversal->load('lines.account')))->response()->setStatusCode(201);
    }

    /** @param Collection<int, JournalEntry> $entries */
    private function attachReversals(Collection $entries, int $tenantId): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $map = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('reverses_entry_id', $entries->pluck('id')->all())
            ->pluck('id', 'reverses_entry_id');

        foreach ($entries as $e) {
            $e->setAttribute('reversed_by_entry_id', $map[$e->id] ?? null);
        }
    }
}
'@

$reportController = @'
<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\LedgerReports;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

// 12.0b: read-only operational reports. accounting.view; tenant comes from the session, never from the request.
final class LedgerReportController extends Controller
{
    public function trialBalance(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($reports->trialBalance((int) $request->user()->tenant_id, $v['as_of'] ?? now()->toDateString()));
    }

    public function incomeStatement(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json($reports->incomeStatement(
            (int) $request->user()->tenant_id,
            $v['from'] ?? now()->startOfYear()->toDateString(),
            $v['to'] ?? now()->toDateString(),
        ));
    }

    public function balanceSheet(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($reports->balanceSheet((int) $request->user()->tenant_id, $v['as_of'] ?? now()->toDateString()));
    }

    /** One account's ledger with a running balance: { data, meta, account, opening_balance }. */
    public function ledger(Request $request, LedgerReports $reports): JsonResponse
    {
        Gate::authorize('accounting.view');
        $v = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $reports->accountLedger(
            (int) $request->user()->tenant_id,
            (int) $v['account_id'],
            $v['from'] ?? null,
            $v['to'] ?? null,
            (int) ($v['per_page'] ?? 50),
            (int) ($v['page'] ?? 1),
        );

        $account = $result['account'];

        return JsonResource::collection($result['paginator'])
            ->additional([
                'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
                'opening_balance' => $result['opening'],
            ])
            ->response();
    }
}
'@

$test = @'
<?php

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Domain\Accounting\Actions\SaveLedgerAccountAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function ledgapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function ledgapiAccount(User $actor, string $code, string $name, string $type): int
{
    return app(SaveLedgerAccountAction::class)->execute($actor, null, ['code' => $code, 'name' => $name, 'type' => $type])->id;
}

function ledgapiPost(User $actor, string $date, string $memo, array $lines)
{
    return app(PostJournalEntryAction::class)->execute($actor, $date, $memo, $lines);
}

it('ledgapi manages the chart of accounts over the API', function () {
    $b = Tenant::factory()->create();
    $theirs = ledgapiAccount(ledgapiUser($b), '9999', 'Theirs', 'asset');

    $a = Tenant::factory()->create();
    $mgr = ledgapiUser($a);
    $viewer = ledgapiUser($a, 'viewer');
    $sales = ledgapiUser($a, 'sales');

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/accounting/accounts')->assertForbidden();

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/accounting/accounts')->assertOk()->assertJsonPath('meta.total', 0);
    $this->postJson('/api/v1/accounting/accounts/defaults')->assertForbidden();
    $this->postJson('/api/v1/accounting/accounts', ['code' => '1', 'name' => 'X', 'type' => 'asset'])->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson('/api/v1/accounting/accounts/defaults')->assertOk()->assertJsonPath('created', 9);
    $this->postJson('/api/v1/accounting/accounts/defaults')->assertOk()->assertJsonPath('created', 0);

    $this->getJson('/api/v1/accounting/accounts')
        ->assertOk()
        ->assertJsonPath('meta.total', 9)
        ->assertJsonPath('data.0.code', '1000')
        ->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson('/api/v1/accounting/accounts?type=liability')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/accounting/accounts?q=cash')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/accounts?active=0')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/accounting/accounts?type=bogus')->assertStatus(422)->assertJsonValidationErrors('type');

    $this->postJson('/api/v1/accounting/accounts', [])->assertStatus(422)->assertJsonValidationErrors(['code', 'name', 'type']);
    $this->postJson('/api/v1/accounting/accounts', ['code' => '1000', 'name' => 'Dup', 'type' => 'asset'])->assertStatus(422)->assertJsonValidationErrors('code');
    $id = (int) $this->postJson('/api/v1/accounting/accounts', ['code' => '4100', 'name' => 'Services', 'type' => 'revenue'])
        ->assertCreated()
        ->assertJsonPath('is_active', true)
        ->json('id');
    $this->putJson("/api/v1/accounting/accounts/{$id}", ['code' => '4100', 'name' => 'Service revenue', 'type' => 'revenue', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('name', 'Service revenue')
        ->assertJsonPath('is_active', false);
    $this->putJson("/api/v1/accounting/accounts/{$theirs}", ['code' => '9999', 'name' => 'Hijack', 'type' => 'asset'])->assertNotFound();
});

it('ledgapi posts, lists, shows and reverses journal entries', function () {
    $b = Tenant::factory()->create();
    $bMgr = ledgapiUser($b);
    $bCash = ledgapiAccount($bMgr, '1000', 'Cash', 'asset');
    $bRev = ledgapiAccount($bMgr, '4000', 'Sales', 'revenue');
    $theirs = ledgapiPost($bMgr, '2026-10-10', 'Theirs', [
        ['account_id' => $bCash, 'debit' => '5', 'credit' => ''],
        ['account_id' => $bRev, 'debit' => '', 'credit' => '5'],
    ])->id;

    $a = Tenant::factory()->create();
    $mgr = ledgapiUser($a);
    $viewer = ledgapiUser($a, 'viewer');
    $cash = ledgapiAccount($mgr, '1000', 'Cash', 'asset');
    $rev = ledgapiAccount($mgr, '4000', 'Sales', 'revenue');

    $payload = [
        'entry_date' => '2026-10-10',
        'memo' => 'Sale',
        'lines' => [
            ['account_id' => $cash, 'debit' => 100, 'credit' => ''],
            ['account_id' => $rev, 'debit' => '', 'credit' => '100.00'],
        ],
    ];

    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/accounting/journal-entries', $payload)->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson('/api/v1/accounting/journal-entries', [])->assertStatus(422)->assertJsonValidationErrors(['entry_date', 'lines']);
    $unbalanced = $payload;
    $unbalanced['lines'][1]['credit'] = '99.99';
    $this->postJson('/api/v1/accounting/journal-entries', $unbalanced)->assertStatus(422)->assertJsonValidationErrors('lines');

    $id = (int) $this->postJson('/api/v1/accounting/journal-entries', $payload)
        ->assertCreated()
        ->assertJsonPath('entry_number', 'JE-0001')
        ->assertJsonPath('total', '100.00')
        ->assertJsonPath('lines.0.account_code', '1000')
        ->assertJsonPath('lines.0.debit', '100.00')
        ->assertJsonPath('lines.1.credit', '100.00')
        ->assertJsonMissingPath('tenant_id')
        ->json('id');

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/accounting/journal-entries')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.total', '100.00')
        ->assertJsonPath('data.0.reversed_by_entry_id', null)
        ->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson("/api/v1/accounting/journal-entries?account_id={$cash}")->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?account_id=999999')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/accounting/journal-entries?from=2026-10-11')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/accounting/journal-entries?to=2026-10-10')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?q=JE-0001')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?q=sale')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?from=10/10/2026')->assertStatus(422)->assertJsonValidationErrors('from');
    $this->getJson("/api/v1/accounting/journal-entries/{$id}")->assertOk()->assertJsonPath('lines.1.account_name', 'Sales');
    $this->getJson("/api/v1/accounting/journal-entries/{$theirs}")->assertNotFound();
    $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", ['reason' => 'Oops'])->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $reversalId = (int) $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", ['reason' => 'Posted twice', 'entry_date' => '2026-10-11'])
        ->assertCreated()
        ->assertJsonPath('entry_number', 'JE-0002')
        ->assertJsonPath('reverses_entry_id', $id)
        ->assertJsonPath('lines.0.credit', '100.00')
        ->json('id');
    $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", ['reason' => 'Again'])->assertStatus(422)->assertJsonValidationErrors('entry');
    $this->postJson("/api/v1/accounting/journal-entries/{$reversalId}/reverse", ['reason' => 'Undo'])->assertStatus(422)->assertJsonValidationErrors('entry');
    $this->postJson("/api/v1/accounting/journal-entries/{$theirs}/reverse", ['reason' => 'Hijack'])->assertNotFound();

    $this->getJson('/api/v1/accounting/journal-entries')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $reversalId);
    $this->getJson("/api/v1/accounting/journal-entries/{$id}")->assertOk()->assertJsonPath('reversed_by_entry_id', $reversalId);
});

it('ledgapi serves the trial balance, income statement, balance sheet and account ledger', function () {
    $b = Tenant::factory()->create();
    $bMgr = ledgapiUser($b);
    $bCash = ledgapiAccount($bMgr, '1000', 'Cash', 'asset');
    $bEquity = ledgapiAccount($bMgr, '3000', 'Equity', 'equity');
    ledgapiPost($bMgr, '2026-10-02', 'Theirs', [
        ['account_id' => $bCash, 'debit' => '9999', 'credit' => ''],
        ['account_id' => $bEquity, 'debit' => '', 'credit' => '9999'],
    ]);

    $a = Tenant::factory()->create();
    $mgr = ledgapiUser($a);
    $viewer = ledgapiUser($a, 'viewer');
    $sales = ledgapiUser($a, 'sales');
    $cash = ledgapiAccount($mgr, '1000', 'Cash', 'asset');
    $ar = ledgapiAccount($mgr, '1100', 'Accounts receivable', 'asset');
    $tax = ledgapiAccount($mgr, '2200', 'Sales tax payable', 'liability');
    $equity = ledgapiAccount($mgr, '3000', 'Equity', 'equity');
    $rev = ledgapiAccount($mgr, '4000', 'Sales', 'revenue');
    $cogs = ledgapiAccount($mgr, '5000', 'Cost of goods sold', 'expense');

    ledgapiPost($mgr, '2026-10-01', 'Investment', [
        ['account_id' => $cash, 'debit' => '1000', 'credit' => ''],
        ['account_id' => $equity, 'debit' => '', 'credit' => '1000'],
    ]);
    ledgapiPost($mgr, '2026-10-10', 'Sale', [
        ['account_id' => $ar, 'debit' => '500', 'credit' => ''],
        ['account_id' => $rev, 'debit' => '', 'credit' => '450'],
        ['account_id' => $tax, 'debit' => '', 'credit' => '50'],
    ]);
    ledgapiPost($mgr, '2026-10-15', 'Cost', [
        ['account_id' => $cogs, 'debit' => '200', 'credit' => ''],
        ['account_id' => $cash, 'debit' => '', 'credit' => '200'],
    ]);

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/accounting/reports/trial-balance')->assertForbidden();

    Sanctum::actingAs($viewer);

    // Trial balance
    $this->getJson('/api/v1/accounting/reports/trial-balance?as_of=2026-10-31')
        ->assertOk()
        ->assertJsonCount(6, 'rows')
        ->assertJsonPath('rows.0.code', '1000')
        ->assertJsonPath('rows.0.debit', '800.00')
        ->assertJsonPath('rows.0.credit', '0.00')
        ->assertJsonPath('rows.2.credit', '50.00')
        ->assertJsonPath('rows.5.debit', '200.00')
        ->assertJsonPath('totals.debit', '1500.00')
        ->assertJsonPath('totals.credit', '1500.00')
        ->assertJsonPath('totals.balanced', true);
    $this->getJson('/api/v1/accounting/reports/trial-balance?as_of=2026-10-05')
        ->assertOk()
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('totals.debit', '1000.00')
        ->assertJsonPath('totals.balanced', true);
    $this->getJson('/api/v1/accounting/reports/trial-balance?as_of=nope')->assertStatus(422)->assertJsonValidationErrors('as_of');

    // Income statement
    $this->getJson('/api/v1/accounting/reports/income-statement?from=2026-10-01&to=2026-10-31')
        ->assertOk()
        ->assertJsonPath('revenue.0.code', '4000')
        ->assertJsonPath('revenue.0.amount', '450.00')
        ->assertJsonPath('expenses.0.amount', '200.00')
        ->assertJsonPath('total_revenue', '450.00')
        ->assertJsonPath('total_expenses', '200.00')
        ->assertJsonPath('net_income', '250.00');
    $this->getJson('/api/v1/accounting/reports/income-statement?from=2026-10-12&to=2026-10-31')
        ->assertOk()
        ->assertJsonPath('total_revenue', '0.00')
        ->assertJsonPath('net_income', '-200.00');
    $this->getJson('/api/v1/accounting/reports/income-statement?from=2026-10-31&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');

    // Balance sheet
    $this->getJson('/api/v1/accounting/reports/balance-sheet?as_of=2026-10-31')
        ->assertOk()
        ->assertJsonPath('assets.0.amount', '800.00')
        ->assertJsonPath('assets.1.amount', '500.00')
        ->assertJsonPath('total_assets', '1300.00')
        ->assertJsonPath('total_liabilities', '50.00')
        ->assertJsonPath('equity.0.amount', '1000.00')
        ->assertJsonPath('current_earnings', '250.00')
        ->assertJsonPath('total_equity', '1250.00')
        ->assertJsonPath('total_liabilities_and_equity', '1300.00')
        ->assertJsonPath('balanced', true);

    // Account ledger with running balance
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$cash}&from=2026-10-01&to=2026-10-31")
        ->assertOk()
        ->assertJsonPath('account.code', '1000')
        ->assertJsonPath('opening_balance', '0.00')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.debit', '1000.00')
        ->assertJsonPath('data.0.balance', '1000.00')
        ->assertJsonPath('data.1.credit', '200.00')
        ->assertJsonPath('data.1.balance', '800.00')
        ->assertJsonPath('meta.total', 2);
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$cash}&from=2026-10-10")
        ->assertOk()
        ->assertJsonPath('opening_balance', '1000.00')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.balance', '800.00');
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$equity}")
        ->assertOk()
        ->assertJsonPath('data.0.credit', '1000.00')
        ->assertJsonPath('data.0.balance', '1000.00'); // credit-normal: shown on its own side
    $this->getJson('/api/v1/accounting/reports/ledger')->assertStatus(422)->assertJsonValidationErrors('account_id');
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$bCash}")->assertNotFound();
});
'@

# ---------------------------------------------------------------- patch (preflight on ORIGINAL file)
$routeAnchor = "require __DIR__ . '/purchasing.php';"
$routeRepl = @'
require __DIR__ . '/purchasing.php';
        // 12.0b: general ledger (routes/accounting.php)
        require __DIR__ . '/accounting.php';
'@
$routeRepl = $routeRepl.TrimEnd("`r", "`n")

$t = (Read-Text $apiPath).Replace("`r`n", "`n")
if (-not $t.Contains('accounting.php')) {
    $c = Count-Sub $t $routeAnchor
    if ($c -ne 1) { throw "PREFLIGHT: anchor occurs $c times (need 1) in routes/api.php: $routeAnchor" }
}

# ---------------------------------------------------------------- write
Write-NoBom 'routes\accounting.php' $routes
Write-NoBom 'app\Domain\Accounting\Actions\ProvisionDefaultChartAction.php' $provision
Write-NoBom 'app\Domain\Accounting\LedgerReports.php' $reports
Write-NoBom 'app\Http\Resources\LedgerAccountResource.php' $accountResource
Write-NoBom 'app\Http\Resources\JournalEntryResource.php' $entryResource
Write-NoBom 'app\Http\Controllers\Api\Accounting\LedgerAccountController.php' $accountController
Write-NoBom 'app\Http\Controllers\Api\Accounting\JournalEntryController.php' $entryController
Write-NoBom 'app\Http\Controllers\Api\Accounting\LedgerReportController.php' $reportController
Write-NoBom 'tests\Feature\Accounting\LedgerApiTest.php' $test
Say 'Wrote 9 accounting API files.'

$res = Patch-Once $apiPath $routeAnchor $routeRepl 'accounting.php'
Say "routes/api.php patch: $res"

# ---------------------------------------------------------------- tests
$ErrorActionPreference = 'Continue'
& php artisan route:clear 2>&1 | Out-Null
$new = & php artisan test tests/Feature/Accounting 2>&1 | Out-String
$newCode = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
Say "Accounting tests (exit $newCode):`n$new"

if ($newCode -eq 0) {
    Say 'Running the full suite (about 5-9 minutes, output appears at the end)...'
    $ErrorActionPreference = 'Continue'
    $full = & php artisan test 2>&1 | Out-String
    $fullCode = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    $tail = ($full -split "`r?`n" | Select-Object -Last 15) -join "`n"
    Say "Full suite (exit $fullCode):`n$tail"
}

$ErrorActionPreference = 'Continue'
& php artisan queue:restart 2>&1 | Out-Null
$ErrorActionPreference = 'Stop'

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): global exception handler (ValidationException -> 422 assumed, as before), frontend (12.1).'
