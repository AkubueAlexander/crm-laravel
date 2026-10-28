$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

foreach ($p in @(
    'routes\invoices.php',
    'app\Http\Controllers\Api\Invoices\InvoiceController.php',
    'app\Http\Resources\InvoiceResource.php',
    'app\Domain\Documents\DocumentPdf.php',
    'resources\views\pdf\document.blade.php',
    'app\Domain\Invoices\Actions\RecordPaymentAction.php',
    'app\Domain\Invoices\Actions\VoidPaymentAction.php'
)) { if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p" } }

$utf8 = New-Object System.Text.UTF8Encoding($false)

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
    $p = Join-Path $repo $Path
    $raw = [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8)
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor must occur exactly once in $Path : $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($p, $t, $utf8)
    return 'patched'
}

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

# ---------------------------------------------------------------- new files

$recordRequest = @'
<?php

namespace App\Http\Requests\Invoices;

use App\Domain\Invoices\Actions\RecordPaymentAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 10.6b: shape check only. RecordPaymentAction re-checks everything under the invoice row lock (2.4):
 * amount format and sign, status, overpayment, future dates.
 */
class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        if (is_int($amount) || is_float($amount)) {
            $this->merge(['amount' => (string) $amount]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', 'string', Rule::in(RecordPaymentAction::METHODS)],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
'@

$voidRequest = @'
<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;

/** 10.6b: shape check only. VoidPaymentAction re-checks permission, status and double reversal (2.4). */
class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
'@

$paymentResource = @'
<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 10.6b: a payment as the SPA sees it. Explicit whitelist: no tenant id, no invoice id. Money is a decimal string.
 * Reversed payments stay in the list (voided_at / void_reason set): payments are never edited or deleted.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'method' => $this->method,
            'paid_on' => $this->paid_on?->format('Y-m-d'),
            'reference' => $this->reference,
            'note' => $this->note,
            'recorded_by' => $this->recorded_by,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
'@

$paymentController = @'
<?php

namespace App\Http\Controllers\Api\Invoices;

use App\Domain\Invoices\Actions\RecordPaymentAction;
use App\Domain\Invoices\Actions\VoidPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\RecordPaymentRequest;
use App\Http\Requests\Invoices\VoidPaymentRequest;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\JsonResponse;

// {invoice} / {payment} are plain ids on purpose (no route-model binding): the Actions look them up with an explicit
// tenant predicate. Validation failures from the Actions are ValidationExceptions (422 with field errors).
final class PaymentController extends Controller
{
    public function store(RecordPaymentRequest $request, int $invoice, RecordPaymentAction $action): JsonResponse
    {
        $v = $request->validated();

        $updated = $action->execute(
            $request->user(),
            $invoice,
            (string) $v['amount'],
            (string) $v['method'],
            $v['paid_on'] ?? null,
            $v['reference'] ?? null,
            $v['note'] ?? null,
        );

        return (new InvoiceResource($updated))->response()->setStatusCode(201);
    }

    public function void(VoidPaymentRequest $request, int $payment, VoidPaymentAction $action): JsonResponse
    {
        $v = $request->validated();

        $updated = $action->execute($request->user(), $payment, (string) $v['reason']);

        return (new InvoiceResource($updated))->response();
    }
}
'@

$paymentsApiTest = @'
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

function paymapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function paymapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    paymapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A one-line zero-tax invoice (total = price) from an accepted quote; issued unless told otherwise. */
function paymapiInvoice(User $actor, string $price = '100.00', bool $issue = true): int
{
    paymapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $quoteId = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Payments test',
        'currency' => 'USD',
        'lines' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => $price, 'tax_category' => 'zero']],
    ])->id;
    DB::table('quotes')->where('id', $quoteId)->update(['status' => 'accepted']);

    $id = app(CreateInvoiceFromQuoteAction::class)->execute($actor, $quoteId, null)->id;

    if ($issue) {
        DB::table('invoices')->where('id', $id)->update(['status' => 'issued']);
    }

    return $id;
}

it('paymapi records partial and full payments and exposes them on the invoice', function () {
    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $id = paymapiInvoice($manager);
    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '40.00', 'method' => 'cash', 'reference' => 'R-1'])
        ->assertCreated()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('amount_paid', '40.00')
        ->assertJsonPath('balance', '60.00')
        ->assertJsonPath('lock_version', 1)
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.method', 'cash')
        ->assertJsonPath('payments.0.amount', '40.00')
        ->assertJsonPath('payments.0.reference', 'R-1')
        ->assertJsonPath('payments.0.paid_on', now()->toDateString())
        ->assertJsonMissingPath('payments.0.tenant_id')
        ->assertJsonMissingPath('payments.0.invoice_id');

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '60.01', 'method' => 'card'])
        ->assertStatus(422)->assertJsonValidationErrors('amount');

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 60, 'method' => 'bank_transfer'])
        ->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('amount_paid', '100.00')
        ->assertJsonPath('balance', '0.00')
        ->assertJsonPath('lock_version', 2)
        ->assertJsonCount(2, 'payments');

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '1.00', 'method' => 'cash'])
        ->assertStatus(422)->assertJsonValidationErrors('invoice');

    $this->getJson("/api/v1/invoices/{$id}")
        ->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonCount(2, 'payments')
        ->assertJsonPath('payments.1.method', 'bank_transfer');

    $this->get("/api/v1/invoices/{$id}/pdf")->assertOk(); // PDF still renders with the paid/balance lines
});

it('paymapi validates the payment body and the invoice status', function () {
    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $id = paymapiInvoice($manager);
    $draft = paymapiInvoice($manager, '50.00', false);
    Sanctum::actingAs($manager);
    $url = "/api/v1/invoices/{$id}/payments";

    $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['amount', 'method']);
    $this->postJson($url, ['amount' => 'abc', 'method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson($url, ['amount' => '0', 'method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson($url, ['amount' => '1.234', 'method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson($url, ['amount' => '10', 'method' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('method');
    $this->postJson($url, ['amount' => '10', 'method' => 'cash', 'paid_on' => '2026-13-45'])->assertStatus(422)->assertJsonValidationErrors('paid_on');
    $this->postJson($url, ['amount' => '10', 'method' => 'cash', 'paid_on' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_on');

    $this->postJson("/api/v1/invoices/{$draft}/payments", ['amount' => '10', 'method' => 'cash'])
        ->assertStatus(422)->assertJsonValidationErrors('invoice'); // draft invoices take no payments

    expect(DB::table('payments')->count())->toBe(0);
});

it('paymapi refuses sales and viewers and never reaches another tenant', function () {
    $b = Tenant::factory()->create();
    $otherManager = paymapiUser($b);

    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $sales = paymapiUser($a, 'sales');
    $viewer = paymapiUser($a, 'viewer');
    $id = paymapiInvoice($manager);

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '10', 'method' => 'cash'])->assertCreated();
    $paymentId = (int) DB::table('payments')->where('invoice_id', $id)->value('id');

    foreach ([$sales, $viewer] as $user) {
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '10', 'method' => 'cash'])->assertForbidden();
        $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'nope'])->assertForbidden();
    }

    Sanctum::actingAs($otherManager);
    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '10', 'method' => 'cash'])->assertNotFound();
    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'nope'])->assertNotFound();

    expect(DB::table('payments')->where('invoice_id', $id)->whereNull('voided_at')->count())->toBe(1);
});

it('paymapi reverses a payment, reopens a paid invoice and refuses a double reversal', function () {
    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $id = paymapiInvoice($manager);
    Sanctum::actingAs($manager);

    $paid = $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '100.00', 'method' => 'cheque'])
        ->assertCreated()
        ->assertJsonPath('status', 'paid');
    $paymentId = (int) $paid->json('payments.0.id');

    $this->postJson("/api/v1/payments/{$paymentId}/void", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => '   '])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/payments/99999999/void', ['reason' => 'x'])->assertNotFound();

    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'Cheque bounced'])
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('amount_paid', '0.00')
        ->assertJsonPath('balance', '100.00')
        ->assertJsonPath('lock_version', 2)
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.void_reason', 'Cheque bounced');

    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'again'])
        ->assertStatus(422)->assertJsonValidationErrors('payment');

    $this->getJson("/api/v1/invoices/{$id}")
        ->assertOk()
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.void_reason', 'Cheque bounced');

    expect(DB::table('payments')->where('id', $paymentId)->value('voided_at'))->not->toBeNull();

    // The reopened invoice takes a fresh payment again.
    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '25.00', 'method' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('amount_paid', '25.00')
        ->assertJsonCount(2, 'payments');
});
'@

# ---------------------------------------------------------------- patches

$resourcePayments = @'
'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => (new PaymentResource($p))->resolve($request))->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
'@

$routesNew = @'
// 10.6b: payments. Record a payment against an issued invoice; reverse (never edit or delete) a payment.
Route::post('invoices/{invoice}/payments', [\App\Http\Controllers\Api\Invoices\PaymentController::class, 'store'])->whereNumber('invoice')->middleware('throttle:30,1');
Route::post('payments/{payment}/void', [\App\Http\Controllers\Api\Invoices\PaymentController::class, 'void'])->whereNumber('payment')->middleware('throttle:30,1');

// The single status-transition endpoint (state-machine pattern 3.0)
'@

$pdfReturn = @'
$live = $invoice->payments->filter(fn ($p) => $p->voided_at === null);
        $paidInfo = [
            'paid' => $this->money($invoice->amount_paid, $invoice->currency),
            'balance' => $this->money(max(0, ((float) $invoice->total) - ((float) $invoice->amount_paid)), $invoice->currency),
            'payments' => $live->map(fn ($p) => [
                'date' => $p->paid_on?->format('Y-m-d') ?? '-',
                'method' => $this->words($p->method),
                'reference' => $p->reference ?? '',
                'amount' => $this->money($p->amount, $invoice->currency),
            ])->values()->all(),
        ];

        return $this->render($tenantId, 'Invoice', $invoice->invoice_number, $invoice->title, $invoice->currency, $meta, $invoice->account_id, $invoice->notes, $invoice->terms, $invoice->lines, $invoice->subtotal, $invoice->tax_total, $invoice->total, $paidInfo);
'@

$pdfViewData = @'
'terms' => $terms,
            'paidInfo' => $paidInfo,
'@

$bladeTotals = @'
<tr class="grand"><td>Total</td><td class="right">{{ $total }}</td></tr>
@if ($paidInfo)
    <tr><td class="muted">Amount paid</td><td class="right">{{ $paidInfo['paid'] }}</td></tr>
    <tr class="grand"><td>Balance due</td><td class="right">{{ $paidInfo['balance'] }}</td></tr>
@endif
'@

$bladePayments = @'
@if ($paidInfo && count($paidInfo['payments']) > 0)
    <div class="block">
        <h2>Payments received</h2>
        <table class="lines">
            <tbody>
                @foreach ($paidInfo['payments'] as $p)
                    <tr>
                        <td>{{ $p['date'] }}</td>
                        <td>{{ $p['method'] }}</td>
                        <td class="muted">{{ $p['reference'] }}</td>
                        <td class="right">{{ $p['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($notes)
'@

$patches = New-Object System.Collections.ArrayList
[void]$patches.Add(@{
    Path = 'app\Http\Resources\InvoiceResource.php'
    Anchor = "'created_at' => `$this->created_at?->toIso8601String(),"
    Replacement = $resourcePayments
    Skip = "'payments' => `$this->whenLoaded"
})
[void]$patches.Add(@{
    Path = 'app\Http\Controllers\Api\Invoices\InvoiceController.php'
    Anchor = "->with('lines')"
    Replacement = "->with(['lines', 'payments'])"
    Skip = "->with(['lines', 'payments'])"
})
[void]$patches.Add(@{
    Path = 'routes\invoices.php'
    Anchor = '// The single status-transition endpoint (state-machine pattern 3.0)'
    Replacement = $routesNew
    Skip = 'PaymentController'
})
[void]$patches.Add(@{
    Path = 'app\Domain\Documents\DocumentPdf.php'
    Anchor = "->with('lines')->findOrFail(`$invoiceId)"
    Replacement = "->with(['lines', 'payments'])->findOrFail(`$invoiceId)"
    Skip = "->with(['lines', 'payments'])->findOrFail(`$invoiceId)"
})
[void]$patches.Add(@{
    Path = 'app\Domain\Documents\DocumentPdf.php'
    Anchor = "return `$this->render(`$tenantId, 'Invoice', `$invoice->invoice_number, `$invoice->title, `$invoice->currency, `$meta, `$invoice->account_id, `$invoice->notes, `$invoice->terms, `$invoice->lines, `$invoice->subtotal, `$invoice->tax_total, `$invoice->total);"
    Replacement = $pdfReturn
    Skip = "`$live = `$invoice->payments"
})
[void]$patches.Add(@{
    Path = 'app\Domain\Documents\DocumentPdf.php'
    Anchor = 'string $subtotal, string $taxTotal, string $total): array'
    Replacement = 'string $subtotal, string $taxTotal, string $total, ?array $paidInfo = null): array'
    Skip = '?array $paidInfo = null'
})
[void]$patches.Add(@{
    Path = 'app\Domain\Documents\DocumentPdf.php'
    Anchor = "'terms' => `$terms,"
    Replacement = $pdfViewData
    Skip = "'paidInfo' => `$paidInfo,"
})
[void]$patches.Add(@{
    Path = 'resources\views\pdf\document.blade.php'
    Anchor = '<tr class="grand"><td>Total</td><td class="right">{{ $total }}</td></tr>'
    Replacement = $bladeTotals
    Skip = 'Balance due'
})
[void]$patches.Add(@{
    Path = 'resources\views\pdf\document.blade.php'
    Anchor = '@if ($notes)'
    Replacement = $bladePayments
    Skip = 'Payments received'
})

# ---------------------------------------------------------------- preflight (nothing written yet)

foreach ($pt in $patches) {
    $p = Join-Path $repo $pt.Path
    $t = [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8).Replace("`r`n", "`n")
    if ($pt.Skip -and $t.Contains($pt.Skip)) { continue }
    $n = Count-Sub $t $pt.Anchor
    if ($n -ne 1) { throw "PREFLIGHT: anchor occurs $n times in $($pt.Path): $($pt.Anchor)" }
}
Say 'Preflight OK.'

# ---------------------------------------------------------------- write + patch

Write-NoBom 'app\Http\Requests\Invoices\RecordPaymentRequest.php' $recordRequest
Write-NoBom 'app\Http\Requests\Invoices\VoidPaymentRequest.php' $voidRequest
Write-NoBom 'app\Http\Resources\PaymentResource.php' $paymentResource
Write-NoBom 'app\Http\Controllers\Api\Invoices\PaymentController.php' $paymentController
Write-NoBom 'tests\Feature\Invoices\InvoicePaymentsApiTest.php' $paymentsApiTest
Say 'Wrote 5 new files.'

foreach ($pt in $patches) {
    $res = Patch-Once $pt.Path $pt.Anchor $pt.Replacement $pt.Skip
    Say ("{0}: {1}" -f $pt.Path, $res)
}

# ---------------------------------------------------------------- tests

$ErrorActionPreference = 'Continue'
& php artisan route:clear 2>&1 | Out-Null

$out1 = (& php artisan test tests\Feature\Invoices 2>&1 | Out-String)
$ok1 = ($LASTEXITCODE -eq 0)
Say '--- tests\Feature\Invoices ---'
Say (($out1 -split "`r?`n" | Select-Object -Last 30) -join "`n")

$ok2 = $false
if ($ok1) {
    $out2 = (& php artisan test --filter=docpdf 2>&1 | Out-String)
    $ok2 = ($LASTEXITCODE -eq 0)
    Say '--- docpdf ---'
    Say (($out2 -split "`r?`n" | Select-Object -Last 15) -join "`n")
}

if ($ok1 -and $ok2) {
    Write-Host 'Running the full suite (about 5 minutes, output appears at the end)...'
    $out3 = (& php artisan test 2>&1 | Out-String)
    Say '--- full suite ---'
    Say (($out3 -split "`r?`n" | Select-Object -Last 15) -join "`n")
} else {
    Say 'Skipped the full suite because the targeted tests did not pass.'
}
$ErrorActionPreference = 'Stop'

Set-Clipboard -Value ($report -join "`r`n")
Say 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): routes/api.php, bootstrap/app.php, RoleCatalog, DevTenantSeeder, DocumentPdfController, DocumentPdfTest, TransitionInvoiceAction, the whole frontend/ tree.'
