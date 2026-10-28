<?php

use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Domain\Purchasing\Actions\ReceivePurchaseOrderAction;
use App\Domain\Purchasing\Actions\SavePurchaseOrderAction;
use App\Domain\Purchasing\Actions\SaveSupplierAction;
use App\Domain\Purchasing\Actions\TransitionPurchaseOrderAction;
use App\Domain\Purchasing\Exceptions\PurchaseOrderTransitionException;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function pordUser(Tenant $tenant, ?string $role = 'manager'): User
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

function pordItem(User $actor, string $sku): int
{
    return app(SaveInventoryItemAction::class)->execute($actor, null, ['sku' => $sku, 'name' => $sku.' item', 'unit' => 'pcs'])->id;
}

function pordSupplier(User $actor, string $name = 'Parts Co'): int
{
    return app(SaveSupplierAction::class)->execute($actor, null, ['name' => $name])->id;
}

/** @param list<array{0:int,1:string,2:string}> $lines [item id, quantity, unit cost] */
function pordPo(User $actor, int $supplierId, array $lines): PurchaseOrder
{
    return app(SavePurchaseOrderAction::class)->execute($actor, null, [
        'supplier_id' => $supplierId,
        'lines' => array_map(fn (array $l) => ['inventory_item_id' => $l[0], 'quantity' => $l[1], 'unit_cost' => $l[2]], $lines),
    ]);
}

function pordOrdered(User $actor, int $supplierId, array $lines): PurchaseOrder
{
    $po = pordPo($actor, $supplierId, $lines);

    return app(TransitionPurchaseOrderAction::class)->execute($actor, $po->id, 'ordered', 0);
}

it('pord saves suppliers with unique names, validation, permissions and tenant isolation', function () {
    $b = Tenant::factory()->create();
    $theirs = pordSupplier(pordUser($b), 'Theirs Ltd');

    $a = Tenant::factory()->create();
    $mgr = pordUser($a);
    $viewer = pordUser($a, 'viewer');
    $save = app(SaveSupplierAction::class);

    $s = $save->execute($mgr, null, ['name' => 'Acme Parts', 'email' => 'a@x.test', 'phone' => '123']);
    expect($s->name)->toBe('Acme Parts')->and($s->is_active)->toBeTrue();

    expect(fn () => $save->execute($mgr, null, ['name' => 'ACME parts']))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, null, ['name' => 'X', 'email' => 'nope']))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, null, ['name' => '']))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, null, ['name' => 'Theirs Ltd']))->not->toThrow(ValidationException::class); // other tenant's name is free

    $u = $save->execute($mgr, $s->id, ['name' => 'Acme Parts Ltd', 'is_active' => false]);
    expect($u->name)->toBe('Acme Parts Ltd')->and($u->is_active)->toBeFalse();

    expect(fn () => $save->execute($viewer, null, ['name' => 'Z']))->toThrow(AuthorizationException::class);
    expect(fn () => $save->execute($mgr, $theirs, ['name' => 'Hijack']))->toThrow(ModelNotFoundException::class);
});

it('pord creates and edits draft purchase orders with server-computed totals', function () {
    $b = Tenant::factory()->create();
    $bMgr = pordUser($b);
    $theirSupplier = pordSupplier($bMgr);
    $theirItem = pordItem($bMgr, 'THEIRS');

    $a = Tenant::factory()->create();
    $mgr = pordUser($a);
    $viewer = pordUser($a, 'viewer');
    $supplier = pordSupplier($mgr);
    $bolt = pordItem($mgr, 'BOLT');
    $nut = pordItem($mgr, 'NUT');
    $save = app(SavePurchaseOrderAction::class);

    $po = pordPo($mgr, $supplier, [[$bolt, '10', '2.50'], [$nut, '2.5', '1.33']]);
    expect($po->po_number)->toBe('PO-0001')
        ->and($po->status)->toBe('draft')
        ->and($po->lock_version)->toBe(0)
        ->and($po->subtotal)->toBe('28.33')
        ->and($po->lines)->toHaveCount(2)
        ->and($po->lines[0]->line_total)->toBe('25.00')
        ->and($po->lines[1]->line_total)->toBe('3.33')
        ->and($po->lines[1]->received_quantity)->toBe('0.000');
    expect(pordPo($mgr, $supplier, [[$bolt, '1', '1']])->po_number)->toBe('PO-0002');

    $bad = fn (array $lines, array $extra = []) => fn () => $save->execute($mgr, null, ['supplier_id' => $supplier, 'lines' => $lines] + $extra);
    expect($bad([]))->toThrow(ValidationException::class);
    expect($bad([['inventory_item_id' => $bolt, 'quantity' => '0', 'unit_cost' => '1']]))->toThrow(ValidationException::class);
    expect($bad([['inventory_item_id' => $bolt, 'quantity' => '1', 'unit_cost' => '-1']]))->toThrow(ValidationException::class);
    expect($bad([['inventory_item_id' => $bolt, 'quantity' => '1', 'unit_cost' => '1'], ['inventory_item_id' => $bolt, 'quantity' => '2', 'unit_cost' => '1']]))->toThrow(ValidationException::class);
    expect($bad([['inventory_item_id' => 999999, 'quantity' => '1', 'unit_cost' => '1']]))->toThrow(ValidationException::class);
    expect($bad([['inventory_item_id' => $theirItem, 'quantity' => '1', 'unit_cost' => '1']]))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, null, ['supplier_id' => $theirSupplier, 'lines' => [['inventory_item_id' => $bolt, 'quantity' => '1', 'unit_cost' => '1']]]))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($viewer, null, ['supplier_id' => $supplier, 'lines' => [['inventory_item_id' => $bolt, 'quantity' => '1', 'unit_cost' => '1']]]))->toThrow(AuthorizationException::class);

    $one = [['inventory_item_id' => $bolt, 'quantity' => '3', 'unit_cost' => '4']];
    expect(fn () => $save->execute($mgr, $po->id, ['supplier_id' => $supplier, 'lines' => $one]))->toThrow(ValidationException::class); // lock_version missing
    expect(fn () => $save->execute($mgr, $po->id, ['supplier_id' => $supplier, 'lock_version' => 5, 'lines' => $one]))->toThrow(PurchaseOrderTransitionException::class);

    $edited = $save->execute($mgr, $po->id, ['supplier_id' => $supplier, 'lock_version' => 0, 'notes' => 'Rush', 'lines' => $one]);
    expect($edited->lock_version)->toBe(1)->and($edited->subtotal)->toBe('12.00')->and($edited->lines)->toHaveCount(1)->and($edited->notes)->toBe('Rush');

    app(TransitionPurchaseOrderAction::class)->execute($mgr, $po->id, 'ordered', 1);
    expect(fn () => $save->execute($mgr, $po->id, ['supplier_id' => $supplier, 'lock_version' => 2, 'lines' => $one]))->toThrow(ValidationException::class);

    // A deactivated supplier cannot take new purchase orders.
    $dead = pordSupplier($mgr, 'Dead Co');
    app(SaveSupplierAction::class)->execute($mgr, $dead, ['name' => 'Dead Co', 'is_active' => false]);
    expect(fn () => pordPo($mgr, $dead, [[$bolt, '1', '1']]))->toThrow(ValidationException::class);
});

it('pord moves a purchase order through the state machine with optimistic locking', function () {
    $b = Tenant::factory()->create();
    $bMgr = pordUser($b);
    $theirs = pordPo($bMgr, pordSupplier($bMgr), [[pordItem($bMgr, 'THEIRS'), '1', '1']]);

    $a = Tenant::factory()->create();
    $mgr = pordUser($a);
    $viewer = pordUser($a, 'viewer');
    $supplier = pordSupplier($mgr);
    $bolt = pordItem($mgr, 'BOLT');
    $t = app(TransitionPurchaseOrderAction::class);

    $po = pordPo($mgr, $supplier, [[$bolt, '5', '2']]);
    expect(fn () => $t->execute($mgr, $po->id, 'ordered', 5))->toThrow(PurchaseOrderTransitionException::class);
    expect(fn () => $t->execute($mgr, $po->id, 'received', 0))->toThrow(ValidationException::class);
    expect(fn () => $t->execute($mgr, $po->id, 'bogus', 0))->toThrow(ValidationException::class);
    expect(fn () => $t->execute($viewer, $po->id, 'ordered', 0))->toThrow(AuthorizationException::class);
    expect(fn () => $t->execute($mgr, $theirs->id, 'ordered', 0))->toThrow(ModelNotFoundException::class);

    $ordered = $t->execute($mgr, $po->id, 'ordered', 0);
    expect($ordered->status)->toBe('ordered')->and($ordered->lock_version)->toBe(1)->and($ordered->ordered_at)->not->toBeNull();

    expect(fn () => $t->execute($mgr, $po->id, 'cancelled', 1))->toThrow(ValidationException::class); // reason required
    $cancelled = $t->execute($mgr, $po->id, 'cancelled', 1, 'Supplier closed');
    expect($cancelled->status)->toBe('cancelled')->and($cancelled->lock_version)->toBe(2)->and($cancelled->cancel_reason)->toBe('Supplier closed');
    expect(fn () => $t->execute($mgr, $po->id, 'ordered', 2))->toThrow(ValidationException::class); // terminal
});

it('pord receives goods partially then fully, writing ledger receipts atomically', function () {
    $a = Tenant::factory()->create();
    $mgr = pordUser($a);
    $viewer = pordUser($a, 'viewer');
    $supplier = pordSupplier($mgr);
    $bolt = pordItem($mgr, 'BOLT');
    $nut = pordItem($mgr, 'NUT');
    $receive = app(ReceivePurchaseOrderAction::class);

    $draft = pordPo($mgr, $supplier, [[$bolt, '1', '1']]);
    expect(fn () => $receive->execute($mgr, $draft->id, 0, [['line_id' => $draft->lines[0]->id, 'quantity' => '1']]))->toThrow(ValidationException::class); // not ordered yet

    $po = pordOrdered($mgr, $supplier, [[$bolt, '10', '2.50'], [$nut, '4', '1']]);
    $po->load('lines');
    [$boltLine, $nutLine] = [$po->lines[0]->id, $po->lines[1]->id];
    $onHand = fn (int $id) => InventoryItem::query()->findOrFail($id)->on_hand;

    expect(fn () => $receive->execute($mgr, $po->id, 9, [['line_id' => $boltLine, 'quantity' => '1']]))->toThrow(PurchaseOrderTransitionException::class);
    expect(fn () => $receive->execute($mgr, $po->id, 1, [['line_id' => $boltLine, 'quantity' => '11']]))->toThrow(ValidationException::class); // over-receive
    expect(fn () => $receive->execute($mgr, $po->id, 1, [['line_id' => 999999, 'quantity' => '1']]))->toThrow(ValidationException::class);
    expect(fn () => $receive->execute($mgr, $po->id, 1, []))->toThrow(ValidationException::class);
    expect(fn () => $receive->execute($viewer, $po->id, 1, [['line_id' => $boltLine, 'quantity' => '1']]))->toThrow(AuthorizationException::class);

    $partial = $receive->execute($mgr, $po->id, 1, [['line_id' => $boltLine, 'quantity' => '6']]);
    expect($partial->status)->toBe('partially_received')->and($partial->lock_version)->toBe(2)->and($partial->received_at)->toBeNull();
    expect($onHand($bolt))->toBe('6.000')->and($onHand($nut))->toBe('0.000');

    $movement = DB::table('inventory_movements')->where('inventory_item_id', $bolt)->first();
    expect($movement->type)->toBe('receipt')->and($movement->reference)->toBe($po->po_number)->and($movement->quantity_delta)->toBe('6.000');

    expect(fn () => app(TransitionPurchaseOrderAction::class)->execute($mgr, $po->id, 'cancelled', 2, 'Too late'))->toThrow(ValidationException::class);
    expect(fn () => $receive->execute($mgr, $po->id, 2, [['line_id' => $boltLine, 'quantity' => '5']]))->toThrow(ValidationException::class); // only 4 left

    $done = $receive->execute($mgr, $po->id, 2, [['line_id' => $boltLine, 'quantity' => '4'], ['line_id' => $nutLine, 'quantity' => '4']]);
    expect($done->status)->toBe('received')->and($done->lock_version)->toBe(3)->and($done->received_at)->not->toBeNull();
    expect($onHand($bolt))->toBe('10.000')->and($onHand($nut))->toBe('4.000');
    expect(fn () => $receive->execute($mgr, $po->id, 3, [['line_id' => $boltLine, 'quantity' => '1']]))->toThrow(ValidationException::class);

    // Atomic: an inactive item blocks the whole receipt, nothing is written.
    $nail = pordItem($mgr, 'NAIL');
    $po2 = pordOrdered($mgr, $supplier, [[$nail, '5', '1']])->load('lines');
    app(SaveInventoryItemAction::class)->execute($mgr, $nail, ['sku' => 'NAIL', 'name' => 'NAIL item', 'is_active' => false]);
    expect(fn () => $receive->execute($mgr, $po2->id, 1, [['line_id' => $po2->lines[0]->id, 'quantity' => '5']]))->toThrow(ValidationException::class);
    expect(DB::table('inventory_movements')->where('inventory_item_id', $nail)->count())->toBe(0);
    $fresh = PurchaseOrder::query()->with('lines')->findOrFail($po2->id);
    expect($fresh->status)->toBe('ordered')->and($fresh->lock_version)->toBe(1)->and($fresh->lines[0]->received_quantity)->toBe('0.000');
});
