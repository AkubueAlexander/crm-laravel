$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

$utf8 = New-Object System.Text.UTF8Encoding($false)
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $c, $utf8)
}

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $raw = [System.IO.File]::ReadAllText($Path, $utf8)
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not exactly once in ${Path}: $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
    return 'patched'
}

# ---------- prerequisites ----------
foreach ($p in @('routes\api.php', 'app\Models\Invoice.php', 'app\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction.php', 'app\Domain\Invoices\Actions\TransitionInvoiceAction.php')) {
    if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p (run 10.4a first)" }
}

$patches = @(
    @{ Path = 'routes\api.php'; Anchor = "require __DIR__ . '/campaign_lifecycle.php';";
       Replacement = "require __DIR__ . '/campaign_lifecycle.php';`n        // 10.4b: invoices (routes/invoices.php)`n        require __DIR__ . '/invoices.php';";
       Skip = "/invoices.php'" }
)

# ---------- preflight ----------
foreach ($p in $patches) {
    $t = [System.IO.File]::ReadAllText((Join-Path $repo $p.Path), $utf8).Replace("`r`n", "`n")
    if ($p.Skip -and $t.Contains($p.Skip)) { continue }
    if ((Count-Sub $t $p.Anchor) -ne 1) { throw "PREFLIGHT: anchor not exactly once in $($p.Path): $($p.Anchor)" }
}

# ---------- files ----------
Write-NoBom 'routes\invoices.php' @'
<?php

use App\Http\Controllers\Api\Invoices\InvoiceController;
use App\Http\Controllers\Api\Invoices\InvoiceLifecycleController;
use Illuminate\Support\Facades\Route;

// 10.4b: invoices. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
// {invoice} is a plain id (no route-model binding): every lookup carries an explicit tenant predicate.
Route::get('invoices', [InvoiceController::class, 'index']);

// Creates a draft invoice from an accepted quote: { quote_id, payment_terms_days? }.
Route::post('invoices', [InvoiceController::class, 'store'])->middleware('throttle:30,1');

Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice');

// The single status-transition endpoint (state-machine pattern 3.0): { to_status, lock_version, note? }.
Route::post('invoices/{invoice}/transition', [InvoiceLifecycleController::class, 'transition'])->whereNumber('invoice')->middleware('throttle:30,1');
'@

Write-NoBom 'app\Http\Requests\Invoices\ListInvoicesRequest.php' @'
<?php

namespace App\Http\Requests\Invoices;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    /** API sort key => column. "invoice_number" sorts by the numeric sequence, not the string. */
    public const SORT_COLUMNS = [
        'invoice_number' => 'invoice_seq',
        'title' => 'title',
        'status' => 'status',
        'total' => 'total',
        'issue_date' => 'issue_date',
        'due_date' => 'due_date',
        'created_at' => 'created_at',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('invoices.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(Invoice::STATUSES)],
            'sort' => ['sometimes', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
'@

Write-NoBom 'app\Http\Requests\Invoices\CreateInvoiceRequest.php' @'
<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape check only: which quote and the payment terms. Everything else (number, status, amounts, lines) comes from
 * the quote on the server. Whether the quote is accepted, owned by the tenant and not yet invoiced is decided by
 * CreateInvoiceFromQuoteAction, which re-checks everything (2.4).
 */
class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'integer', 'min:1'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
'@

Write-NoBom 'app\Http\Requests\Invoices\TransitionInvoiceRequest.php' @'
<?php

namespace App\Http\Requests\Invoices;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape check only. Whether the move is legal and whether a note is required is decided by
 * TransitionInvoiceAction, which re-checks everything (2.4).
 */
class TransitionInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in(Invoice::STATUSES)],
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
'@

Write-NoBom 'app\Http\Resources\InvoiceResource.php' @'
<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice as the SPA sees it (10.4). Explicit whitelist: no tenant id. Money is a decimal string.
 * Lines appear only when loaded (show/store); the list carries lines_count instead.
 * Line "variant_id" mirrors the quote resource (stored as product_variant_id).
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_seq' => $this->invoice_seq,
            'invoice_number' => $this->invoice_number,
            'status' => $this->status,
            'lock_version' => $this->lock_version,
            'quote_id' => $this->quote_id,
            'title' => $this->title,
            'account_id' => $this->account_id,
            'contact_id' => $this->contact_id,
            'currency' => $this->currency,
            'payment_terms_days' => $this->payment_terms_days,
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'terms' => $this->terms,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_by' => $this->created_by,
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'product_id' => $l->product_id,
                'variant_id' => $l->product_variant_id,
                'sku' => $l->sku,
                'name' => $l->name,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_price' => $l->unit_price,
                'discount_percent' => $l->discount_percent,
                'tax_category' => $l->tax_category,
                'tax_rate' => $l->tax_rate,
                'line_total' => $l->line_total,
                'tax_amount' => $l->tax_amount,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
'@

Write-NoBom 'app\Http\Controllers\Api\Invoices\InvoiceController.php' @'
<?php

namespace App\Http\Controllers\Api\Invoices;

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\CreateInvoiceRequest;
use App\Http\Requests\Invoices\ListInvoicesRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

// {invoice} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class InvoiceController extends Controller
{
    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'desc';
        $column = ListInvoicesRequest::SORT_COLUMNS[$v['sort'] ?? 'invoice_number'];

        $invoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->withCount('lines')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('invoice_number', 'ilike', $like)->orWhere('title', 'ilike', $like);
                });
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function show(Request $request, int $invoice): InvoiceResource
    {
        Gate::authorize('invoices.view');

        return new InvoiceResource(
            Invoice::query()
                ->where('tenant_id', (int) $request->user()->tenant_id)
                ->with('lines')
                ->findOrFail($invoice),
        );
    }

    public function store(CreateInvoiceRequest $request, CreateInvoiceFromQuoteAction $action): JsonResponse
    {
        $v = $request->validated();

        $invoice = $action->execute(
            $request->user(),
            (int) $v['quote_id'],
            isset($v['payment_terms_days']) ? (int) $v['payment_terms_days'] : null,
        );

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }
}
'@

Write-NoBom 'app\Http\Controllers\Api\Invoices\InvoiceLifecycleController.php' @'
<?php

namespace App\Http\Controllers\Api\Invoices;

use App\Domain\Invoices\Actions\TransitionInvoiceAction;
use App\Domain\Invoices\Exceptions\InvoiceTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\TransitionInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;

// {invoice} is a plain id on purpose (no route-model binding): the Action looks it up with an explicit tenant predicate.
final class InvoiceLifecycleController extends Controller
{
    public function transition(TransitionInvoiceRequest $request, int $invoice, TransitionInvoiceAction $action): JsonResponse
    {
        $v = $request->validated();

        try {
            $updated = $action->execute($request->user(), $invoice, $v['to_status'], (int) $v['lock_version'], $v['note'] ?? null);
        } catch (InvoiceTransitionException $e) {
            if ($e->isStale() && $e->invoice !== null) {
                // 409: the client's lock_version is behind. Return the current invoice so it can reconcile.
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->reason,
                    'current' => (new InvoiceResource($e->invoice))->resolve($request),
                ], 409);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['to_status' => [$e->getMessage()]],
                'code' => $e->reason,
            ], 422);
        }

        return (new InvoiceResource($updated))->response();
    }
}
'@

Write-NoBom 'tests\Feature\Invoices\InvoiceApiTest.php' @'
<?php

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function invapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function invapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    invapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A one-line quote (zero tax, so total = price) pushed straight into the given status. */
function invapiQuote(User $actor, string $title = 'Website build', string $price = '100.00', string $status = 'accepted'): int
{
    invapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $id = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => $title,
        'currency' => 'USD',
        'lines' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => $price, 'tax_category' => 'zero']],
    ])->id;

    DB::table('quotes')->where('id', $id)->update(['status' => $status]);

    return $id;
}

function invapiInvoice(User $actor, string $title = 'Website build', string $price = '100.00', ?int $terms = null): int
{
    $quoteId = invapiQuote($actor, $title, $price);
    invapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(CreateInvoiceFromQuoteAction::class)->execute($actor, $quoteId, $terms)->id;
}

it('invapi lists only the tenants invoices with filters, search and sorting', function () {
    $b = Tenant::factory()->create();
    invapiInvoice(invapiUser($b), 'Other tenant');

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $noRole = invapiUser($a, null);
    $i1 = invapiInvoice($manager, 'Alpha', '100.00');
    $i2 = invapiInvoice($manager, 'Beta', '300.00');
    $i3 = invapiInvoice($manager, 'Gamma', '200.00');
    DB::table('invoices')->where('id', $i2)->update(['status' => 'issued']);

    Sanctum::actingAs($manager);

    $this->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.invoice_number', 'INV-00003') // newest first by default
        ->assertJsonPath('data.0.lines_count', 1)
        ->assertJsonMissingPath('data.0.tenant_id');

    $this->getJson('/api/v1/invoices?status=issued')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $i2);
    $this->getJson('/api/v1/invoices?q=alp')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $i1);
    $this->getJson('/api/v1/invoices?sort=total&direction=asc')->assertOk()->assertJsonPath('data.0.total', '100.00');
    $this->getJson('/api/v1/invoices?sort=total&direction=desc')->assertOk()->assertJsonPath('data.0.total', '300.00');
    $this->getJson('/api/v1/invoices?sort=bogus')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/invoices?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');

    Sanctum::actingAs($noRole);
    $this->getJson('/api/v1/invoices')->assertForbidden();
});

it('invapi shows one invoice with its lines and never another tenants', function () {
    $b = Tenant::factory()->create();
    $theirs = invapiInvoice(invapiUser($b));

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $viewer = invapiUser($a, 'viewer');
    $mine = invapiInvoice($manager, 'Mine', '250.00');

    Sanctum::actingAs($viewer); // view-only is enough to read
    $this->getJson("/api/v1/invoices/{$mine}")
        ->assertOk()
        ->assertJsonPath('id', $mine)
        ->assertJsonPath('title', 'Mine')
        ->assertJsonPath('total', '250.00')
        ->assertJsonPath('status', 'draft')
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.name', 'Item')
        ->assertJsonMissingPath('tenant_id');

    $this->getJson("/api/v1/invoices/{$theirs}")->assertNotFound();
    $this->getJson('/api/v1/invoices/abc')->assertNotFound();
});

it('invapi creates a draft invoice from an accepted quote', function () {
    $b = Tenant::factory()->create();
    $theirsQuote = invapiQuote(invapiUser($b));

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $sales = invapiUser($a, 'sales');
    $viewer = invapiUser($a, 'viewer');
    $quote = invapiQuote($manager);
    $draftQuote = invapiQuote($manager, 'Not yet', '10.00', 'draft');

    Sanctum::actingAs($sales);
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote])->assertForbidden(); // sales may view, not manage
    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote])->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson('/api/v1/invoices', [])->assertStatus(422)->assertJsonValidationErrors('quote_id');
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote, 'payment_terms_days' => 400])->assertStatus(422)->assertJsonValidationErrors('payment_terms_days');
    $this->postJson('/api/v1/invoices', ['quote_id' => $draftQuote])->assertStatus(422)->assertJsonValidationErrors('quote_id');
    $this->postJson('/api/v1/invoices', ['quote_id' => $theirsQuote])->assertNotFound();

    $this->postJson('/api/v1/invoices', ['quote_id' => $quote, 'payment_terms_days' => 45])
        ->assertCreated()
        ->assertJsonPath('invoice_number', 'INV-00001')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('lock_version', 0)
        ->assertJsonPath('payment_terms_days', 45)
        ->assertJsonPath('total', '100.00')
        ->assertJsonPath('issue_date', null)
        ->assertJsonCount(1, 'lines')
        ->assertJsonMissingPath('tenant_id');

    $this->postJson('/api/v1/invoices', ['quote_id' => $quote])->assertStatus(422)->assertJsonValidationErrors('quote_id'); // already invoiced
});

it('invapi walks the transition endpoint with 409, 422 and permission checks', function () {
    $b = Tenant::factory()->create();
    $theirs = invapiInvoice(invapiUser($b));

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $sales = invapiUser($a, 'sales');
    $id = invapiInvoice($manager, 'Deal', '100.00', 15);
    $go = fn (int $inv, string $to, int $lock, array $extra = []) => $this->postJson("/api/v1/invoices/{$inv}/transition", array_merge(['to_status' => $to, 'lock_version' => $lock], $extra));

    Sanctum::actingAs($sales);
    $go($id, 'issued', 0)->assertForbidden();

    Sanctum::actingAs($manager);
    $go($theirs, 'issued', 0)->assertNotFound();
    $go($id, 'bogus', 0)->assertStatus(422)->assertJsonValidationErrors('to_status');
    $go($id, 'paid', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition'); // draft cannot be paid

    $go($id, 'issued', 9)
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_invoice')
        ->assertJsonPath('current.id', $id)
        ->assertJsonPath('current.status', 'draft')
        ->assertJsonPath('current.lock_version', 0);

    $go($id, 'issued', 0)
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('issue_date', now()->toDateString())
        ->assertJsonPath('due_date', now()->addDays(15)->toDateString())
        ->assertJsonMissingPath('tenant_id');

    $go($id, 'void', 1)->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go($id, 'void', 1, ['note' => '  '])->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go($id, 'paid', 1)->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('lock_version', 2);
    $go($id, 'void', 2, ['note' => 'too late'])->assertStatus(422)->assertJsonPath('code', 'invalid_transition'); // paid is terminal

    $voidMe = invapiInvoice($manager, 'Cancelled', '50.00');
    $go($voidMe, 'void', 0, ['note' => 'Customer cancelled'])
        ->assertOk()
        ->assertJsonPath('status', 'void')
        ->assertJsonPath('void_reason', 'Customer cancelled');

    expect(DB::table('invoices')->where('id', $theirs)->value('status'))->toBe('draft');
});
'@

# ---------- apply patches ----------
foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Say "$res : $($p.Path)"
}

# ---------- tests ----------
$ErrorActionPreference = 'Continue'
Say 'Running the invoice tests...'
$new = & php artisan test --no-ansi tests/Feature/Invoices 2>&1 | Out-String
$newCode = $LASTEXITCODE
Say (($new -split "`r?`n" | Select-Object -Last 40) -join "`n")

if ($newCode -ne 0) {
    Say 'Invoice tests FAILED: skipping the full suite. Paste the report.'
} else {
    Say 'Running the full suite (about 5 minutes, output appears at the end)...'
    $full = & php artisan test --no-ansi 2>&1 | Out-String
    $fullCode = $LASTEXITCODE
    if ($fullCode -eq 0) {
        Say (($full -split "`r?`n" | Select-Object -Last 6) -join "`n")
    } else {
        Say 'SUITE FAILED:'
        Say (($full -split "`r?`n" | Select-Object -Last 120) -join "`n")
    }
}
& php artisan route:clear 2>&1 | Out-Null
& php artisan queue:restart 2>&1 | Out-Null
$ErrorActionPreference = 'Stop'

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): frontend (10.4c), the 10.4a files (unchanged), AppShell/router (no nav yet).'
