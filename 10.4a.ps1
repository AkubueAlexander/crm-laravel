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
$catalogPath = 'app\Domain\Users\RoleCatalog.php'
$seederPath = 'database\seeders\DevTenantSeeder.php'
foreach ($p in @($catalogPath, $seederPath, 'app\Models\Quote.php', 'app\Models\QuoteLine.php', 'database\migrations')) {
    if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p" }
}

# ---------- migration name (computed) ----------
$migDir = Join-Path $repo 'database\migrations'
$existing = @(Get-ChildItem -Path $migDir -Filter '*.php' | ForEach-Object { $_.Name })
[Array]::Sort($existing, [System.StringComparer]::Ordinal)
$suffix = '_create_invoices_tables.php'
$migExists = @($existing | Where-Object { $_.EndsWith($suffix) }).Count -gt 0
$migName = $null
if (-not $migExists) {
    $newest = $existing[$existing.Length - 1]
    $newestTs = [DateTime]::ParseExact($newest.Substring(0, 17), 'yyyy_MM_dd_HHmmss', [System.Globalization.CultureInfo]::InvariantCulture)
    $cand = Get-Date
    if ($cand -le $newestTs) { $cand = $newestTs.AddMinutes(1) }
    $migName = $cand.ToString('yyyy_MM_dd_HHmmss') + $suffix
    if ([string]::CompareOrdinal($existing[$existing.Length - 1], $migName) -gt 0) { throw "An existing migration sorts after $migName" }
}

# ---------- patches ----------
$patches = @(
    @{ Path = $catalogPath; Anchor = "'quotes.approve',";
       Replacement = "'quotes.approve',`n        'invoices.view',`n        'invoices.manage',"; Skip = "'invoices.manage'," },
    @{ Path = $catalogPath; Anchor = "'quotes.view', 'quotes.manage', // sales: build their own quotes";
       Replacement = "'quotes.view', 'quotes.manage', // sales: build their own quotes`n                'invoices.view', // sales: see invoices"; Skip = '// sales: see invoices' },
    @{ Path = $seederPath; Anchor = "'quotes.approve',";
       Replacement = "'quotes.approve',`n        'invoices.view',`n        'invoices.manage',"; Skip = "'invoices.manage'," }
)

# ---------- preflight ----------
foreach ($p in $patches) {
    $t = [System.IO.File]::ReadAllText((Join-Path $repo $p.Path), $utf8).Replace("`r`n", "`n")
    if ($p.Skip -and $t.Contains($p.Skip)) { continue }
    if ((Count-Sub $t $p.Anchor) -ne 1) { throw "PREFLIGHT: anchor not exactly once in $($p.Path): $($p.Anchor)" }
}

# ---------- files ----------
if ($migName) {
Write-NoBom ("database\migrations\" + $migName) @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 10.4a: invoices. An invoice is a SNAPSHOT of an accepted quote (lines, prices, tax as quoted): later quote or
// catalog edits never change it. Real tax calculation (TaxJar/Avalara) will plug in server-side later; today the
// amounts are carried over from the quote. Payments (10.6) will add their own table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('invoice_seq');
            $table->string('invoice_number', 24);
            // draft | issued | paid | void
            $table->string('status', 16)->default('draft');
            // 3.0 pattern: optimistic-concurrency counter, bumped on every status transition.
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->unsignedInteger('quote_seq')->nullable();
            $table->string('title', 160);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_seq']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'invoice_number']);
        });

        // One live (non-void) invoice per quote; a voided one can be re-invoiced. Race guard behind the friendly pre-check.
        DB::statement("CREATE UNIQUE INDEX invoices_one_live_per_quote ON invoices (tenant_id, quote_seq) WHERE quote_seq IS NOT NULL AND status <> 'void'");

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->string('tax_category', 16);
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
'@
}

Write-NoBom 'app\Models\Invoice.php' @'
<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 10.4: an invoice, created from an accepted quote. Never mass-assigned: CreateInvoiceFromQuoteAction and
 * TransitionInvoiceAction use forceFill with values they computed.
 */
class Invoice extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'issued', 'paid', 'void'];

    protected $fillable = [];

    protected $attributes = [
        'status' => 'draft',
        'lock_version' => 0,
        'currency' => 'USD',
        'payment_terms_days' => 30,
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected $casts = [
        'invoice_seq' => 'integer',
        'lock_version' => 'integer',
        'quote_seq' => 'integer',
        'payment_terms_days' => 'integer',
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
'@

Write-NoBom 'app\Models\InvoiceLine.php' @'
<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 10.4: one line of an invoice, a snapshot copied from the quote line. Written only by CreateInvoiceFromQuoteAction. */
class InvoiceLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $casts = [
        'position' => 'integer',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
'@

Write-NoBom 'app\Domain\Invoices\Exceptions\InvoiceTransitionException.php' @'
<?php

namespace App\Domain\Invoices\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * One exception for every refused invoice transition. `reason` is the stable machine code the controller puts in
 * the JSON body (same { message, errors, code } shape as the quote/campaign/deal 409/422).
 */
final class InvoiceTransitionException extends RuntimeException
{
    public const STALE = 'stale_invoice';

    public const INVALID = 'invalid_transition';

    public const NOTE_REQUIRED = 'note_required';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Invoice $invoice = null,
    ) {
        parent::__construct($message);
    }

    /** 3.0 / 409: carries the locked, current server-side invoice so the client can reconcile. */
    public static function stale(Invoice $invoice): self
    {
        return new self(self::STALE, 'This invoice was updated by someone else - refresh to see the latest status.', $invoice);
    }

    public static function invalid(string $from, string $to): self
    {
        return new self(self::INVALID, "An invoice cannot move from {$from} to {$to}.");
    }

    public static function noteRequired(): self
    {
        return new self(self::NOTE_REQUIRED, 'Give a reason when voiding an invoice.');
    }

    public function isStale(): bool
    {
        return $this->reason === self::STALE;
    }
}
'@

Write-NoBom 'app\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction.php' @'
<?php

namespace App\Domain\Invoices\Actions;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.4a: turns an ACCEPTED quote into a draft invoice (snapshot of lines and amounts as quoted). Tenant, number,
 * status and every amount come from the server (1.3); the caller only picks the quote and the payment terms.
 * One live (non-void) invoice per quote: a voided one can be re-invoiced.
 *
 * Tax seam: amounts are carried over from the quote. A TaxJar/Avalara calculation would be applied here, server-side.
 */
final class CreateInvoiceFromQuoteAction
{
    public const DEFAULT_TERMS_DAYS = 30;

    public function execute(User $actor, int $quoteId, ?int $paymentTermsDays = null): Invoice
    {
        Gate::forUser($actor)->authorize('invoices.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $terms = $paymentTermsDays ?? self::DEFAULT_TERMS_DAYS;
        if ($terms < 0 || $terms > 365) {
            throw ValidationException::withMessages(['payment_terms_days' => ['Payment terms must be between 0 and 365 days.']]);
        }

        try {
            return DB::transaction(function () use ($actor, $quoteId, $tenantId, $terms): Invoice {
                // Serialises invoice-number allocation per tenant.
                DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id');

                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $quote = Quote::query()->where('tenant_id', $tenantId)->findOrFail($quoteId);

                if ($quote->status !== 'accepted') {
                    throw ValidationException::withMessages(['quote_id' => ['Only accepted quotes can be invoiced.']]);
                }

                $alreadyInvoiced = Invoice::query()
                    ->where('tenant_id', $tenantId)
                    ->where('quote_seq', $quote->quote_seq)
                    ->where('status', '<>', 'void')
                    ->exists();

                if ($alreadyInvoiced) {
                    throw ValidationException::withMessages(['quote_id' => ['This quote already has an invoice.']]);
                }

                $seq = (int) DB::table('invoices')->where('tenant_id', $tenantId)->max('invoice_seq') + 1;

                $invoice = (new Invoice())->forceFill([
                    'tenant_id' => $tenantId,
                    'invoice_seq' => $seq,
                    'invoice_number' => 'INV-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                    'status' => 'draft',
                    'quote_id' => $quote->id,
                    'quote_seq' => $quote->quote_seq,
                    'title' => $quote->title,
                    'account_id' => $quote->account_id,
                    'contact_id' => $quote->contact_id,
                    'currency' => $quote->currency,
                    'payment_terms_days' => $terms,
                    'notes' => $quote->notes,
                    'terms' => $quote->terms,
                    'subtotal' => $quote->subtotal,
                    'tax_total' => $quote->tax_total,
                    'total' => $quote->total,
                    'created_by' => $actor->id,
                ]);
                $invoice->save();

                $lines = QuoteLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('quote_id', $quote->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                foreach ($lines as $i => $l) {
                    (new InvoiceLine())->forceFill([
                        'tenant_id' => $tenantId,
                        'invoice_id' => $invoice->id,
                        'position' => $i,
                        'product_id' => $l->product_id,
                        'product_variant_id' => $l->product_variant_id,
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
                    ])->save();
                }

                return $invoice->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            // Race guard: a concurrent create won (same number or same quote). The caller can simply retry.
            throw ValidationException::withMessages(['quote_id' => ['Could not create the invoice. Please try again.']]);
        }
    }
}
'@

Write-NoBom 'app\Domain\Invoices\Actions\TransitionInvoiceAction.php' @'
<?php

namespace App\Domain\Invoices\Actions;

use App\Domain\Invoices\Exceptions\InvoiceTransitionException;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.4a: the ONLY place an invoice changes status (state-machine pattern 3.0). The client sends { to_status,
 * lock_version, note? }; the server decides everything else. lock_version is bumped on every transition and a
 * mismatch is a 409 carrying the current invoice.
 *
 * draft -> issued | void ; issued -> paid | void ; paid and void are terminal. Issuing stamps issue_date (today) and
 * due_date (today + payment terms). Marking paid is manual until 10.6 adds real payment records.
 */
final class TransitionInvoiceAction
{
    public const TRANSITIONS = [
        'draft' => ['issued', 'void'],
        'issued' => ['paid', 'void'],
    ];

    public function execute(User $actor, int $invoiceId, string $toStatus, int $lockVersion, ?string $note = null): Invoice
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        if (! in_array($toStatus, Invoice::STATUSES, true)) {
            throw ValidationException::withMessages(['to_status' => ['Unknown status.']]);
        }

        Gate::forUser($actor)->authorize('invoices.manage');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($actor, $invoiceId, $tenantId, $toStatus, $lockVersion, $note): Invoice {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $invoice = Invoice::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->lock_version !== $lockVersion) {
                throw InvoiceTransitionException::stale($invoice);
            }

            if (! in_array($toStatus, self::TRANSITIONS[$invoice->status] ?? [], true)) {
                throw InvoiceTransitionException::invalid($invoice->status, $toStatus);
            }

            $now = now();
            $changes = ['status' => $toStatus, 'lock_version' => $invoice->lock_version + 1];

            switch ($toStatus) {
                case 'issued':
                    $today = Carbon::today();
                    $changes += [
                        'issued_at' => $now,
                        'issued_by' => $actor->id,
                        'issue_date' => $today->toDateString(),
                        'due_date' => $today->copy()->addDays((int) $invoice->payment_terms_days)->toDateString(),
                    ];
                    break;

                case 'paid':
                    $changes += ['paid_at' => $now, 'paid_by' => $actor->id];
                    break;

                case 'void':
                    if ($note === null) {
                        throw InvoiceTransitionException::noteRequired();
                    }
                    $changes += ['voided_at' => $now, 'voided_by' => $actor->id, 'void_reason' => $note];
                    break;
            }

            $invoice->forceFill($changes)->save();

            return $invoice;
        });
    }
}
'@

Write-NoBom 'tests\Feature\Invoices\InvoiceActionsTest.php' @'
<?php

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Domain\Invoices\Actions\TransitionInvoiceAction;
use App\Domain\Invoices\Exceptions\InvoiceTransitionException;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function invactSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function invactUser(Tenant $tenant, string $role = 'manager'): User
{
    invactSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A quote pushed straight into the given status. Default lines: A (standard tax) + B (zero tax) = 70.00 + 1.50 = 71.50. */
function invactQuote(User $actor, string $status = 'accepted'): int
{
    invactSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $id = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Website build',
        'currency' => 'USD',
        'notes' => 'Thanks',
        'lines' => [
            ['name' => 'A', 'quantity' => 2, 'unit_price' => '10.00', 'tax_category' => 'standard'],
            ['name' => 'B', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero'],
        ],
    ])->id;

    DB::table('quotes')->where('id', $id)->update(['status' => $status]);

    return $id;
}

function invactCatch(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('invact snapshots an accepted quote into a draft invoice', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $quoteId = invactQuote($manager);
    $action = app(CreateInvoiceFromQuoteAction::class);

    $invoice = $action->execute($manager, $quoteId);

    expect($invoice->invoice_number)->toBe('INV-00001')
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->lock_version)->toBe(0)
        ->and($invoice->title)->toBe('Website build')
        ->and($invoice->quote_id)->toBe($quoteId)
        ->and($invoice->payment_terms_days)->toBe(30)
        ->and($invoice->issue_date)->toBeNull()
        ->and($invoice->subtotal)->toBe('70.00')
        ->and($invoice->tax_total)->toBe('1.50')
        ->and($invoice->total)->toBe('71.50')
        ->and($invoice->created_by)->toBe($manager->id)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines[0]->name)->toBe('A')
        ->and($invoice->lines[0]->quantity)->toBe('2.000')
        ->and($invoice->lines[0]->line_total)->toBe('20.00')
        ->and($invoice->lines[0]->tax_amount)->toBe('1.50');

    $second = $action->execute($manager, invactQuote($manager));
    expect($second->invoice_number)->toBe('INV-00002');
});

it('invact refuses a non-accepted quote, a duplicate and bad terms, but allows re-invoicing after a void', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $action = app(CreateInvoiceFromQuoteAction::class);

    $draftQuote = invactQuote($manager, 'draft');
    expect(fn () => $action->execute($manager, $draftQuote))->toThrow(ValidationException::class, 'Only accepted quotes can be invoiced.');

    $quoteId = invactQuote($manager);
    expect(fn () => $action->execute($manager, $quoteId, 400))->toThrow(ValidationException::class, 'between 0 and 365');

    $first = $action->execute($manager, $quoteId, 15);
    expect($first->payment_terms_days)->toBe(15);
    expect(fn () => $action->execute($manager, $quoteId))->toThrow(ValidationException::class, 'already has an invoice');

    DB::table('invoices')->where('id', $first->id)->update(['status' => 'void']);
    $again = $action->execute($manager, $quoteId);
    expect($again->invoice_number)->toBe('INV-00002')->and($again->status)->toBe('draft');
});

it('invact enforces permissions and tenant isolation', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $theirs = invactQuote(invactUser($b));
    $managerA = invactUser($a);
    $viewerA = invactUser($a, 'viewer');
    $salesA = invactUser($a, 'sales');
    $mine = invactQuote($managerA);
    $action = app(CreateInvoiceFromQuoteAction::class);

    expect(fn () => $action->execute($viewerA, $mine))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($salesA, $mine))->toThrow(AuthorizationException::class); // sales may view, not manage

    invactSwitch($a);
    expect(fn () => $action->execute($managerA, $theirs))->toThrow(ModelNotFoundException::class);

    invactSwitch($b);
    expect(DB::table('invoices')->count())->toBe(0);
});

it('invact walks an invoice from draft to paid and stamps the dates', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $invoice = app(CreateInvoiceFromQuoteAction::class)->execute($manager, invactQuote($manager), 15);
    $transition = app(TransitionInvoiceAction::class);

    $issued = $transition->execute($manager, $invoice->id, 'issued', 0);
    expect($issued->status)->toBe('issued')
        ->and($issued->lock_version)->toBe(1)
        ->and($issued->issued_by)->toBe($manager->id)
        ->and($issued->issue_date->toDateString())->toBe(now()->toDateString())
        ->and($issued->due_date->toDateString())->toBe(now()->addDays(15)->toDateString());

    $paid = $transition->execute($manager, $invoice->id, 'paid', 1);
    expect($paid->status)->toBe('paid')->and($paid->lock_version)->toBe(2)->and($paid->paid_at)->not->toBeNull();

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'void', 2, 'oops'));
    expect($e)->toBeInstanceOf(InvoiceTransitionException::class)->and($e->reason)->toBe('invalid_transition'); // paid is terminal
});

it('invact answers a stale lock_version with the current invoice', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $invoice = app(CreateInvoiceFromQuoteAction::class)->execute($manager, invactQuote($manager));

    $e = invactCatch(fn () => app(TransitionInvoiceAction::class)->execute($manager, $invoice->id, 'issued', 5));

    expect($e)->toBeInstanceOf(InvoiceTransitionException::class)
        ->and($e->isStale())->toBeTrue()
        ->and($e->invoice->status)->toBe('draft')
        ->and($e->invoice->lock_version)->toBe(0);
    expect(Invoice::query()->where('tenant_id', $tenant->id)->value('status'))->toBe('draft');
});

it('invact voids only with a reason and refuses illegal moves and unauthorized actors', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $sales = invactUser($tenant, 'sales');
    $invoice = app(CreateInvoiceFromQuoteAction::class)->execute($manager, invactQuote($manager));
    $transition = app(TransitionInvoiceAction::class);

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'paid', 0)); // draft cannot be paid
    expect($e->reason)->toBe('invalid_transition');

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'void', 0, '   '));
    expect($e->reason)->toBe('note_required');

    expect(fn () => $transition->execute($manager, $invoice->id, 'bogus', 0))->toThrow(ValidationException::class);
    expect(fn () => $transition->execute($sales, $invoice->id, 'issued', 0))->toThrow(AuthorizationException::class);

    $voided = $transition->execute($manager, $invoice->id, 'void', 0, 'Customer cancelled');
    expect($voided->status)->toBe('void')
        ->and($voided->lock_version)->toBe(1)
        ->and($voided->void_reason)->toBe('Customer cancelled')
        ->and($voided->voided_at)->not->toBeNull();

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'issued', 1));
    expect($e->reason)->toBe('invalid_transition'); // void is terminal
});
'@

# ---------- apply patches ----------
foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Say "$res : $($p.Path)"
}
if ($migName) { Say "created : database\migrations\$migName" } else { Say 'migration already present: skipped' }

# ---------- migrate ----------
$ErrorActionPreference = 'Continue'
Say 'Migrating...'
$mig = & php artisan migrate --no-interaction 2>&1 | Out-String
$migCode = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
Say $mig.Trim()
if ($migCode -ne 0) { Say 'MIGRATION FAILED: stopping.'; Set-Clipboard -Value ($report -join "`r`n"); Read-Host 'Press Enter to close'; return }

# ---------- re-provision existing tenants (new permissions) ----------
$tmp = Join-Path $repo 'reprovision-tmp.php'
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
try {
    $ErrorActionPreference = 'Continue'
    $prov = & php $tmp 2>&1 | Out-String
    $ErrorActionPreference = 'Stop'
    Say $prov.Trim()
} finally {
    if (Test-Path $tmp) { Remove-Item $tmp -Force }
}

# ---------- tests ----------
$ErrorActionPreference = 'Continue'
Say 'Running the new tests...'
$new = & php artisan test --no-ansi tests/Feature/Invoices 2>&1 | Out-String
Say (($new -split "`r?`n" | Select-Object -Last 25) -join "`n")

Say 'Running the full suite (about 5 minutes, output appears at the end)...'
$full = & php artisan test --no-ansi 2>&1 | Out-String
$fullCode = $LASTEXITCODE
& php artisan queue:restart 2>&1 | Out-Null
$ErrorActionPreference = 'Stop'

if ($fullCode -eq 0) {
    Say (($full -split "`r?`n" | Select-Object -Last 6) -join "`n")
} else {
    Say 'SUITE FAILED:'
    Say (($full -split "`r?`n" | Select-Object -Last 120) -join "`n")
}

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): routes/api.php (no invoice routes yet, 10.4b), Invoice controllers/requests/resource (10.4b), frontend (10.4c), tests that assert role permission lists (if any fail, paste the report).'
