<?php

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function stkactSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function stkactUser(Tenant $tenant, ?string $role = 'manager'): User
{
    stkactSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function stkactItem(User $actor, string $sku = 'SKU-1', string $reorder = '5'): InventoryItem
{
    stkactSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(SaveInventoryItemAction::class)->execute($actor, null, ['sku' => $sku, 'name' => 'Bolt', 'unit' => 'pcs', 'reorder_level' => $reorder]);
}

/** The validation errors a callable throws, keyed by field (empty when it does not throw). */
function stkactErrors(callable $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('stkact creates and edits items', function () {
    $manager = stkactUser(Tenant::factory()->create());
    $save = app(SaveInventoryItemAction::class);

    $item = $save->execute($manager, null, ['sku' => ' bolt-m8 ', 'name' => 'Bolt M8', 'unit' => 'pcs', 'reorder_level' => '25']);
    expect($item->sku)->toBe('BOLT-M8')
        ->and($item->name)->toBe('Bolt M8')
        ->and($item->unit)->toBe('pcs')
        ->and($item->on_hand)->toBe('0.000')
        ->and($item->reorder_level)->toBe('25.000')
        ->and($item->is_active)->toBeTrue();

    $edited = $save->execute($manager, $item->id, ['sku' => 'bolt-m8', 'name' => 'Bolt M8 zinc', 'unit' => 'box', 'reorder_level' => '10.5', 'is_active' => false]);
    expect($edited->id)->toBe($item->id)
        ->and($edited->name)->toBe('Bolt M8 zinc')
        ->and($edited->unit)->toBe('box')
        ->and($edited->reorder_level)->toBe('10.500')
        ->and($edited->is_active)->toBeFalse();

    expect(stkactErrors(fn () => $save->execute($manager, $item->id, ['sku' => 'OTHER', 'name' => 'x'])))->toHaveKey('sku'); // immutable
    expect(stkactErrors(fn () => $save->execute($manager, null, ['sku' => 'bolt-m8', 'name' => 'Dup'])))->toHaveKey('sku'); // case-insensitive duplicate
    expect(InventoryItem::query()->count())->toBe(1);
});

it('stkact validates item fields', function () {
    $manager = stkactUser(Tenant::factory()->create());
    $save = app(SaveInventoryItemAction::class);

    expect(array_keys(stkactErrors(fn () => $save->execute($manager, null, ['sku' => '!!', 'name' => '', 'unit' => str_repeat('x', 30), 'reorder_level' => '-1']))))
        ->toEqualCanonicalizing(['sku', 'name', 'unit', 'reorder_level']);

    expect(stkactErrors(fn () => $save->execute($manager, null, ['name' => 'No sku'])))->toHaveKey('sku');
    expect(stkactErrors(fn () => $save->execute($manager, null, ['sku' => 'A1', 'name' => 'x', 'reorder_level' => '1.2345'])))->toHaveKey('reorder_level');
    expect(InventoryItem::query()->count())->toBe(0);
});

it('stkact gives sales and viewers read access only', function () {
    $tenant = Tenant::factory()->create();
    $manager = stkactUser($tenant);
    $sales = stkactUser($tenant, 'sales');
    $viewer = stkactUser($tenant, 'viewer');
    $item = stkactItem($manager);

    foreach ([$sales, $viewer] as $user) {
        stkactSwitch($tenant);
        expect($user->can('inventory.view'))->toBeTrue()
            ->and($user->can('inventory.manage'))->toBeFalse();
        expect(fn () => app(SaveInventoryItemAction::class)->execute($user, null, ['sku' => 'NEW', 'name' => 'x']))->toThrow(AuthorizationException::class);
        expect(fn () => app(RecordStockMovementAction::class)->execute($user, $item->id, 'receipt', '1'))->toThrow(AuthorizationException::class);
    }

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('stkact keeps an append-only ledger whose balance always matches', function () {
    $manager = stkactUser(Tenant::factory()->create());
    $item = stkactItem($manager);
    $act = app(RecordStockMovementAction::class);

    $m1 = $act->execute($manager, $item->id, 'receipt', '10', 'PO-1');
    expect($m1->type)->toBe('receipt')
        ->and($m1->quantity_delta)->toBe('10.000')
        ->and($m1->balance_after)->toBe('10.000')
        ->and($m1->reference)->toBe('PO-1');

    $m2 = $act->execute($manager, $item->id, 'issue', '3.5');
    expect($m2->quantity_delta)->toBe('-3.500')->and($m2->balance_after)->toBe('6.500');

    // Cannot issue more than is on hand: nothing is written.
    $errors = stkactErrors(fn () => $act->execute($manager, $item->id, 'issue', '7'));
    expect($errors)->toHaveKey('quantity')->and($errors['quantity'][0])->toContain('6.500');

    $m3 = $act->execute($manager, $item->id, 'adjustment', '-1.5', null, 'Stock count');
    expect($m3->quantity_delta)->toBe('-1.500')->and($m3->balance_after)->toBe('5.000');

    $fresh = InventoryItem::query()->findOrFail($item->id);
    expect($fresh->on_hand)->toBe('5.000');

    $rows = InventoryMovement::query()->where('inventory_item_id', $item->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and((float) $rows->sum('quantity_delta'))->toBe(5.0)
        ->and($rows->last()->balance_after)->toBe($fresh->on_hand);

    // Append-only: no edits, no deletes.
    expect(fn () => $m1->forceFill(['note' => 'tamper'])->save())->toThrow(LogicException::class);
    expect(fn () => $m1->delete())->toThrow(LogicException::class);
    expect(InventoryMovement::query()->count())->toBe(3);
});

it('stkact validates movements', function () {
    $manager = stkactUser(Tenant::factory()->create());
    $item = stkactItem($manager);
    $act = app(RecordStockMovementAction::class);

    foreach (['0', '-1', '1.2345', 'abc', ''] as $bad) {
        expect(stkactErrors(fn () => $act->execute($manager, $item->id, 'receipt', $bad)))->toHaveKey('quantity');
    }

    expect(stkactErrors(fn () => $act->execute($manager, $item->id, 'bogus', '1')))->toHaveKey('type');
    expect(stkactErrors(fn () => $act->execute($manager, $item->id, 'adjustment', '2')))->toHaveKey('note'); // reason required
    expect(stkactErrors(fn () => $act->execute($manager, $item->id, 'adjustment', '0', null, 'why')))->toHaveKey('quantity');
    expect(stkactErrors(fn () => $act->execute($manager, $item->id, 'receipt', '1', str_repeat('r', 121))))->toHaveKey('reference');

    // Inactive items take no movements.
    app(SaveInventoryItemAction::class)->execute($manager, $item->id, ['sku' => $item->sku, 'name' => 'Bolt', 'is_active' => false]);
    expect(stkactErrors(fn () => $act->execute($manager, $item->id, 'receipt', '1')))->toHaveKey('item');

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('stkact never crosses tenants', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = stkactUser($a);
    $managerB = stkactUser($b);
    $itemA = stkactItem($managerA, 'SHARED');
    app(RecordStockMovementAction::class)->execute($managerA, $itemA->id, 'receipt', '4');

    stkactSwitch($b);
    expect(fn () => app(RecordStockMovementAction::class)->execute($managerB, $itemA->id, 'receipt', '5'))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(SaveInventoryItemAction::class)->execute($managerB, $itemA->id, ['sku' => 'SHARED', 'name' => 'Hijack']))->toThrow(ModelNotFoundException::class);

    // The same SKU is fine in another tenant.
    $itemB = app(SaveInventoryItemAction::class)->execute($managerB, null, ['sku' => 'SHARED', 'name' => 'Theirs']);
    expect($itemB->id)->not->toBe($itemA->id);

    stkactSwitch($a);
    expect(DB::table('inventory_items')->where('id', $itemA->id)->value('name'))->toBe('Bolt');
    expect((float) DB::table('inventory_items')->where('id', $itemA->id)->value('on_hand'))->toBe(4.0);
});

it('stkact backs the rules with database constraints', function () {
    $manager = stkactUser(Tenant::factory()->create());
    $item = stkactItem($manager);

    expect(fn () => DB::transaction(fn () => DB::table('inventory_items')->where('id', $item->id)->update(['on_hand' => -1])))
        ->toThrow(QueryException::class);
});
