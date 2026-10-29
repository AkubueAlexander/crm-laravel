<?php

use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Domain\Purchasing\Actions\SavePurchaseOrderAction;
use App\Domain\Purchasing\Actions\SaveSupplierAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function pordapiUser(Tenant $tenant, ?string $role = 'manager'): User
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

function pordapiItem(User $actor, string $sku): int
{
    return app(SaveInventoryItemAction::class)->execute($actor, null, ['sku' => $sku, 'name' => $sku.' item', 'unit' => 'pcs'])->id;
}

function pordapiSupplier(User $actor, string $name = 'Parts Co'): int
{
    return app(SaveSupplierAction::class)->execute($actor, null, ['name' => $name])->id;
}

function pordapiPo(User $actor, int $supplierId, int $itemId, string $qty = '10', string $cost = '2.50')
{
    return app(SavePurchaseOrderAction::class)->execute($actor, null, [
        'supplier_id' => $supplierId,
        'lines' => [['inventory_item_id' => $itemId, 'quantity' => $qty, 'unit_cost' => $cost]],
    ]);
}

it('pordapi manages suppliers over the API with permissions and tenant isolation', function () {
    $b = Tenant::factory()->create();
    $theirs = pordapiSupplier(pordapiUser($b), 'Theirs');

    $a = Tenant::factory()->create();
    $mgr = pordapiUser($a);
    $sales = pordapiUser($a, 'sales');
    $viewer = pordapiUser($a, 'viewer');
    $none = pordapiUser($a, null);

    Sanctum::actingAs($sales);
    $this->postJson('/api/v1/suppliers', ['name' => 'X'])->assertForbidden();
    $this->getJson('/api/v1/suppliers')->assertOk();

    Sanctum::actingAs($mgr);
    $this->postJson('/api/v1/suppliers', [])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/suppliers', ['name' => 'Bad', 'email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('email');
    $id = (int) $this->postJson('/api/v1/suppliers', ['name' => 'Acme', 'email' => 'a@x.test'])
        ->assertCreated()
        ->assertJsonPath('name', 'Acme')
        ->assertJsonPath('is_active', true)
        ->assertJsonMissingPath('tenant_id')
        ->json('id');
    $this->postJson('/api/v1/suppliers', ['name' => 'ACME'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/suppliers', ['name' => 'Beta'])->assertCreated();

    $this->putJson("/api/v1/suppliers/{$id}", ['name' => 'Acme Ltd', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('name', 'Acme Ltd')
        ->assertJsonPath('is_active', false);
    $this->putJson("/api/v1/suppliers/{$theirs}", ['name' => 'Hijack'])->assertNotFound();

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson('/api/v1/suppliers?active=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Beta');
    $this->getJson('/api/v1/suppliers?q=acm')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/suppliers?sort=bogus')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson("/api/v1/suppliers/{$id}")->assertOk()->assertJsonPath('name', 'Acme Ltd');
    $this->getJson("/api/v1/suppliers/{$theirs}")->assertNotFound();

    Sanctum::actingAs($none);
    $this->getJson('/api/v1/suppliers')->assertForbidden();
});

it('pordapi creates, lists, shows and edits purchase orders', function () {
    $b = Tenant::factory()->create();
    $bMgr = pordapiUser($b);
    $theirs = pordapiPo($bMgr, pordapiSupplier($bMgr), pordapiItem($bMgr, 'THEIRS'))->id;

    $a = Tenant::factory()->create();
    $mgr = pordapiUser($a);
    $viewer = pordapiUser($a, 'viewer');
    $supplier = pordapiSupplier($mgr);
    $bolt = pordapiItem($mgr, 'BOLT');
    $nut = pordapiItem($mgr, 'NUT');

    $payload = [
        'supplier_id' => $supplier,
        'expected_date' => '2026-12-01',
        'notes' => 'Rush',
        'lines' => [
            ['inventory_item_id' => $bolt, 'quantity' => 10, 'unit_cost' => 2.5],
            ['inventory_item_id' => $nut, 'quantity' => '2.5', 'unit_cost' => '1.33'],
        ],
    ];

    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/purchase-orders', $payload)->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson('/api/v1/purchase-orders', [])->assertStatus(422)->assertJsonValidationErrors(['supplier_id', 'lines']);
    $zero = $payload;
    $zero['lines'][0]['quantity'] = '0';
    $this->postJson('/api/v1/purchase-orders', $zero)->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

    $id = (int) $this->postJson('/api/v1/purchase-orders', $payload)
        ->assertCreated()
        ->assertJsonPath('po_number', 'PO-0001')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('lock_version', 0)
        ->assertJsonPath('subtotal', '28.33')
        ->assertJsonPath('supplier.name', 'Parts Co')
        ->assertJsonPath('lines.0.sku', 'BOLT')
        ->assertJsonPath('lines.0.line_total', '25.00')
        ->assertJsonPath('lines.0.remaining', '10.000')
        ->assertJsonMissingPath('tenant_id')
        ->json('id');

    $one = ['supplier_id' => $supplier, 'lines' => [['inventory_item_id' => $bolt, 'quantity' => '3', 'unit_cost' => '4']]];
    $this->putJson("/api/v1/purchase-orders/{$id}", $one)->assertStatus(422)->assertJsonValidationErrors('lock_version');
    $this->putJson("/api/v1/purchase-orders/{$id}", $one + ['lock_version' => 9])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_purchase_order')
        ->assertJsonPath('current.id', $id)
        ->assertJsonPath('current.lock_version', 0);
    $this->putJson("/api/v1/purchase-orders/{$id}", $one + ['lock_version' => 0])
        ->assertOk()
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('subtotal', '12.00')
        ->assertJsonCount(1, 'lines');
    $this->putJson("/api/v1/purchase-orders/{$theirs}", $one + ['lock_version' => 0])->assertNotFound();

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/purchase-orders')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.lines_count', 1)
        ->assertJsonPath('data.0.supplier.name', 'Parts Co')
        ->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson('/api/v1/purchase-orders?status=ordered')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/purchase-orders?q=PO-0001')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/purchase-orders?q=parts')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/purchase-orders?sort=bogus')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson("/api/v1/purchase-orders/{$id}")->assertOk()->assertJsonPath('lines.0.name', 'BOLT item');
    $this->getJson("/api/v1/purchase-orders/{$theirs}")->assertNotFound();
});

it('pordapi transitions and receives with 409 on a stale lock and ledger receipts', function () {
    $a = Tenant::factory()->create();
    $mgr = pordapiUser($a);
    $viewer = pordapiUser($a, 'viewer');
    $supplier = pordapiSupplier($mgr);
    $bolt = pordapiItem($mgr, 'BOLT');
    $po = pordapiPo($mgr, $supplier, $bolt);
    $id = $po->id;
    $lineId = $po->lines[0]->id;

    Sanctum::actingAs($viewer);
    $this->postJson("/api/v1/purchase-orders/{$id}/transition", ['to_status' => 'ordered', 'lock_version' => 0])->assertForbidden();
    $this->postJson("/api/v1/purchase-orders/{$id}/receive", ['lock_version' => 0, 'receipts' => [['line_id' => $lineId, 'quantity' => '1']]])->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson("/api/v1/purchase-orders/{$id}/transition", ['to_status' => 'bogus', 'lock_version' => 0])->assertStatus(422)->assertJsonValidationErrors('to_status');
    $this->postJson("/api/v1/purchase-orders/{$id}/transition", ['to_status' => 'received', 'lock_version' => 0])->assertStatus(422)->assertJsonValidationErrors('to_status');
    $this->postJson("/api/v1/purchase-orders/{$id}/transition", ['to_status' => 'ordered', 'lock_version' => 5])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_purchase_order')
        ->assertJsonPath('current.lock_version', 0);
    $this->postJson("/api/v1/purchase-orders/{$id}/transition", ['to_status' => 'ordered', 'lock_version' => 0])
        ->assertOk()
        ->assertJsonPath('status', 'ordered')
        ->assertJsonPath('lock_version', 1);

    $receive = fn (int $lock, array $receipts) => $this->postJson("/api/v1/purchase-orders/{$id}/receive", ['lock_version' => $lock, 'receipts' => $receipts]);
    $receive(9, [['line_id' => $lineId, 'quantity' => '1']])->assertStatus(409)->assertJsonPath('code', 'stale_purchase_order');
    $receive(1, [['line_id' => $lineId, 'quantity' => '11']])->assertStatus(422)->assertJsonValidationErrors('receipts.0.quantity');
    $receive(1, [])->assertStatus(422);
    $receive(1, [['line_id' => $lineId, 'quantity' => 6]])
        ->assertOk()
        ->assertJsonPath('status', 'partially_received')
        ->assertJsonPath('lock_version', 2)
        ->assertJsonPath('lines.0.received_quantity', '6.000')
        ->assertJsonPath('lines.0.remaining', '4.000');

    $this->getJson("/api/v1/inventory/items/{$bolt}")->assertOk()->assertJsonPath('on_hand', '6.000');
    $this->getJson("/api/v1/inventory/items/{$bolt}/movements")->assertOk()->assertJsonPath('data.0.type', 'receipt')->assertJsonPath('data.0.reference', 'PO-0001');

    $this->postJson("/api/v1/purchase-orders/{$id}/transition", ['to_status' => 'cancelled', 'lock_version' => 2, 'note' => 'Too late'])->assertStatus(422)->assertJsonValidationErrors('to_status');
    $receive(2, [['line_id' => $lineId, 'quantity' => '4']])->assertOk()->assertJsonPath('status', 'received')->assertJsonPath('lock_version', 3);
    $this->getJson("/api/v1/inventory/items/{$bolt}")->assertOk()->assertJsonPath('on_hand', '10.000');

    // Cancelling needs a reason.
    $other = pordapiPo($mgr, $supplier, $bolt, '1', '1')->id;
    $this->postJson("/api/v1/purchase-orders/{$other}/transition", ['to_status' => 'cancelled', 'lock_version' => 0])->assertStatus(422)->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/purchase-orders/{$other}/transition", ['to_status' => 'cancelled', 'lock_version' => 0, 'note' => 'Not needed'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('cancel_reason', 'Not needed');
});
