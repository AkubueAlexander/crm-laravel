<?php

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Domain\Accounting\Actions\SaveLedgerAccountAction;
use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Domain\Purchasing\Actions\SavePurchaseOrderAction;
use App\Domain\Purchasing\Actions\SaveSupplierAction;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\LedgerAccount;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

function numseqSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function numseqActor(Tenant $tenant): User
{
    numseqSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** Posts one balanced entry for the tenant and returns its number. */
function numseqJournal(Tenant $tenant, User $actor): string
{
    numseqSwitch($tenant);

    $accounts = LedgerAccount::query()->where('tenant_id', $tenant->id)->orderBy('id')->get();

    if ($accounts->count() < 2) {
        $save = app(SaveLedgerAccountAction::class);
        $type = LedgerAccount::TYPES[0];
        $accounts = collect([
            $save->execute($actor, null, ['code' => 'NS-A', 'name' => 'Account A', 'type' => $type]),
            $save->execute($actor, null, ['code' => 'NS-B', 'name' => 'Account B', 'type' => $type]),
        ]);
    }

    return app(PostJournalEntryAction::class)->execute($actor, '2026-11-01', 'numbering test', [
        ['account_id' => $accounts[0]->id, 'debit' => '10.00', 'credit' => ''],
        ['account_id' => $accounts[1]->id, 'debit' => '', 'credit' => '10.00'],
    ])->entry_number;
}

/** Creates one draft PO (with its own supplier and item) and returns its number. */
function numseqPo(Tenant $tenant, User $actor): string
{
    static $n = 0;
    $n++;
    numseqSwitch($tenant);

    $supplier = app(SaveSupplierAction::class)->execute($actor, null, ['name' => "Supplier {$n}"]);
    $item = app(SaveInventoryItemAction::class)->execute($actor, null, ['sku' => "NS{$n}", 'name' => "Item {$n}"]);

    return app(SavePurchaseOrderAction::class)->execute($actor, null, [
        'supplier_id' => $supplier->id,
        'lines' => [['inventory_item_id' => $item->id, 'quantity' => '2', 'unit_cost' => '5.00']],
    ])->po_number;
}

/** Creates a quote, forces it to accepted, invoices it and returns the invoice number. */
function numseqInvoice(Tenant $tenant, User $actor): string
{
    numseqSwitch($tenant);

    $quote = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Numbering test',
        'lines' => [['name' => 'Thing', 'quantity' => '1', 'unit_price' => '10.00', 'tax_category' => 'standard']],
    ]);
    Quote::query()->whereKey($quote->id)->firstOrFail()->forceFill(['status' => 'accepted'])->save();

    return app(CreateInvoiceFromQuoteAction::class)->execute($actor, $quote->id)->invoice_number;
}

it('numseq numbers journal entries per tenant, interleaved', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorA = numseqActor($a);
    $actorB = numseqActor($b);

    $numbers = [
        numseqJournal($a, $actorA),
        numseqJournal($a, $actorA),
        numseqJournal($b, $actorB),
        numseqJournal($a, $actorA),
    ];

    expect($numbers)->toBe(['JE-0001', 'JE-0002', 'JE-0001', 'JE-0003']);
});

it('numseq numbers purchase orders per tenant, interleaved', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorA = numseqActor($a);
    $actorB = numseqActor($b);

    $numbers = [
        numseqPo($a, $actorA),
        numseqPo($a, $actorA),
        numseqPo($b, $actorB),
        numseqPo($a, $actorA),
    ];

    expect($numbers)->toBe(['PO-0001', 'PO-0002', 'PO-0001', 'PO-0003']);
});

it('numseq numbers invoices per tenant, interleaved', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorA = numseqActor($a);
    $actorB = numseqActor($b);

    $numbers = [
        numseqInvoice($a, $actorA),
        numseqInvoice($a, $actorA),
        numseqInvoice($b, $actorB),
        numseqInvoice($a, $actorA),
    ];

    expect($numbers)->toBe(['INV-00001', 'INV-00002', 'INV-00001', 'INV-00003']);
});