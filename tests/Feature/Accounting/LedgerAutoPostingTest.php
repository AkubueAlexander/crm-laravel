<?php

use App\Domain\Accounting\Actions\ProvisionDefaultChartAction;
use App\Domain\Invoices\Actions\RecordPaymentAction;
use App\Domain\Invoices\Actions\TransitionInvoiceAction;
use App\Domain\Invoices\Actions\VoidPaymentAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(fn () => config(['logging.default' => 'null']));

/** @return array{0: Tenant, 1: User, 2: User} tenant, admin, biller (invoices.manage only, no accounting.manage) */
function lpostTenant(bool $chart): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('admin');
    $biller = User::factory()->create(['tenant_id' => $tenant->id]);
    $biller->givePermissionTo('invoices.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if ($chart) {
        app(ProvisionDefaultChartAction::class)->execute($admin);
    }

    return [$tenant, $admin, $biller];
}

function lpostInvoice(Tenant $tenant, User $by, string $total = '120.00', string $tax = '20.00'): Invoice
{
    $seq = ((int) Invoice::query()->where('tenant_id', $tenant->id)->max('invoice_seq')) + 1;
    $invoice = (new Invoice())->forceFill([
        'tenant_id' => $tenant->id,
        'invoice_seq' => $seq,
        'invoice_number' => 'INV-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
        'status' => 'draft',
        'title' => 'Posting test',
        'currency' => 'USD',
        'payment_terms_days' => 30,
        'subtotal' => number_format((float) $total - (float) $tax, 2, '.', ''),
        'tax_total' => $tax,
        'total' => $total,
        'created_by' => $by->id,
    ]);
    $invoice->save();

    return $invoice;
}

/** @return \Illuminate\Support\Collection<int, JournalEntry> */
function lpostEntries(Tenant $tenant, string $sourceType)
{
    return JournalEntry::query()->where('tenant_id', $tenant->id)->where('source_type', $sourceType)->orderBy('id')->get();
}

/** @return list<array{0: string, 1: string, 2: string}> code, debit, credit */
function lpostLines(JournalEntry $entry): array
{
    return JournalLine::query()
        ->where('tenant_id', $entry->tenant_id)
        ->where('journal_entry_id', $entry->id)
        ->orderBy('position')
        ->orderBy('id')
        ->get()
        ->map(fn (JournalLine $l) => [
            (string) LedgerAccount::query()->where('tenant_id', $l->tenant_id)->find($l->ledger_account_id)->code,
            (string) $l->debit,
            (string) $l->credit,
        ])
        ->all();
}

it('posts receivable, revenue and sales tax when an invoice is issued, without accounting.manage', function () {
    [$other] = lpostTenant(true);
    [$tenant, , $biller] = lpostTenant(true);

    $invoice = lpostInvoice($tenant, $biller);
    app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'issued', $invoice->lock_version);

    $entries = lpostEntries($tenant, 'invoice');
    expect($entries)->toHaveCount(1);
    expect($entries[0]->source_id)->toBe($invoice->id);
    expect(lpostLines($entries[0]))->toEqual([
        ['1100', '120.00', '0.00'],
        ['4000', '0.00', '100.00'],
        ['2200', '0.00', '20.00'],
    ]);
    expect(JournalEntry::query()->where('tenant_id', $other->id)->count())->toBe(0);
});

it('skips the tax line when the invoice has no tax', function () {
    [$tenant, , $biller] = lpostTenant(true);

    $invoice = lpostInvoice($tenant, $biller, '80.00', '0.00');
    app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'issued', $invoice->lock_version);

    $entries = lpostEntries($tenant, 'invoice');
    expect($entries)->toHaveCount(1);
    expect(lpostLines($entries[0]))->toEqual([
        ['1100', '80.00', '0.00'],
        ['4000', '0.00', '80.00'],
    ]);
});

it('does not post and does not block the invoice when the tenant has no chart', function () {
    [$tenant, , $biller] = lpostTenant(false);

    $invoice = lpostInvoice($tenant, $biller);
    $issued = app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'issued', $invoice->lock_version);

    expect($issued->status)->toBe('issued');
    expect(JournalEntry::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('posts cash against receivable when a payment is recorded', function () {
    [$tenant, , $biller] = lpostTenant(true);

    $invoice = lpostInvoice($tenant, $biller);
    app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'issued', $invoice->lock_version);
    $paid = app(RecordPaymentAction::class)->execute($biller, $invoice->id, '50.00', 'cash');

    $entries = lpostEntries($tenant, 'payment');
    expect($entries)->toHaveCount(1);
    expect($entries[0]->source_id)->toBe($paid->payments->first()->id);
    expect(lpostLines($entries[0]))->toEqual([
        ['1000', '50.00', '0.00'],
        ['1100', '0.00', '50.00'],
    ]);
});

it('posts the settlement when an invoice is marked as paid', function () {
    [$tenant, , $biller] = lpostTenant(true);

    $invoice = lpostInvoice($tenant, $biller);
    $issued = app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'issued', $invoice->lock_version);
    app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'paid', $issued->lock_version);

    $entries = lpostEntries($tenant, 'payment');
    expect($entries)->toHaveCount(1);
    expect(lpostLines($entries[0]))->toEqual([
        ['1000', '120.00', '0.00'],
        ['1100', '0.00', '120.00'],
    ]);
});

it('reverses the payment entry when a payment is reversed', function () {
    [$tenant, , $biller] = lpostTenant(true);

    $invoice = lpostInvoice($tenant, $biller);
    app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'issued', $invoice->lock_version);
    $paid = app(RecordPaymentAction::class)->execute($biller, $invoice->id, '50.00', 'cash');
    app(VoidPaymentAction::class)->execute($biller, $paid->payments->first()->id, 'entered by mistake');

    $original = lpostEntries($tenant, 'payment')->first();
    $reversal = lpostEntries($tenant, 'payment_void');
    expect($reversal)->toHaveCount(1);
    expect($reversal[0]->reverses_entry_id)->toBe($original->id);
    expect(lpostLines($reversal[0]))->toEqual([
        ['1000', '0.00', '50.00'],
        ['1100', '50.00', '0.00'],
    ]);
});

it('reverses the invoice entry when an issued invoice is voided', function () {
    [$tenant, , $biller] = lpostTenant(true);

    $invoice = lpostInvoice($tenant, $biller);
    $issued = app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'issued', $invoice->lock_version);
    app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'void', $issued->lock_version, 'wrong customer');

    $original = lpostEntries($tenant, 'invoice')->first();
    $reversal = lpostEntries($tenant, 'invoice_void');
    expect($reversal)->toHaveCount(1);
    expect($reversal[0]->reverses_entry_id)->toBe($original->id);
    expect(lpostLines($reversal[0]))->toEqual([
        ['1100', '0.00', '120.00'],
        ['4000', '100.00', '0.00'],
        ['2200', '20.00', '0.00'],
    ]);
});

it('posts nothing when a draft invoice is voided', function () {
    [$tenant, , $biller] = lpostTenant(true);

    $invoice = lpostInvoice($tenant, $biller);
    app(TransitionInvoiceAction::class)->execute($biller, $invoice->id, 'void', $invoice->lock_version, 'not needed');

    expect(JournalEntry::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
