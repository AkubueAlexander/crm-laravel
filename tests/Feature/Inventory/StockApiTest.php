<?php

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function stkapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function stkapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    stkapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function stkapiItem(User $actor, string $sku, string $name = 'Item', string $reorder = '0', ?string $stock = null): int
{
    stkapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $id = app(SaveInventoryItemAction::class)->execute($actor, null, ['sku' => $sku, 'name' => $name, 'unit' => 'pcs', 'reorder_level' => $reorder])->id;

    if ($stock !== null) {
        app(RecordStockMovementAction::class)->execute($actor, $id, 'receipt', $stock);
    }

    return $id;
}

it('stkapi lists only the tenants items with filters, search and sorting', function () {
    $b = Tenant::factory()->create();
    stkapiItem(stkapiUser($b), 'THEIRS', 'Other tenant');

    $a = Tenant::factory()->create();
    $manager = stkapiUser($a);
    $viewer = stkapiUser($a, 'viewer');
    $noRole = stkapiUser($a, null);
    $bolt = stkapiItem($manager, 'BOLT', 'Bolt', '10', '4');   // low stock
    $nut = stkapiItem($manager, 'NUT', 'Nut', '10', '50');     // fine
    $washer = stkapiItem($manager, 'WASHER', 'Washer');        // no reorder level
    app(SaveInventoryItemAction::class)->execute($manager, $washer, ['sku' => 'WASHER', 'name' => 'Washer', 'is_active' => false]);

    Sanctum::actingAs($viewer); // read-only is enough to list
    $this->getJson('/api/v1/inventory/items')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.sku', 'BOLT') // sku ascending by default
        ->assertJsonPath('data.0.on_hand', '4.000')
        ->assertJsonPath('data.0.low_stock', true)
        ->assertJsonPath('data.1.low_stock', false)
        ->assertJsonMissingPath('data.0.tenant_id');

    $this->getJson('/api/v1/inventory/items?low_stock=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $bolt);
    $this->getJson('/api/v1/inventory/items?active=0')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $washer);
    $this->getJson('/api/v1/inventory/items?active=1')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/inventory/items?q=nu')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $nut);
    $this->getJson('/api/v1/inventory/items?sort=on_hand&direction=desc')->assertOk()->assertJsonPath('data.0.id', $nut);
    $this->getJson('/api/v1/inventory/items?sort=bogus')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/inventory/items?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');

    Sanctum::actingAs($noRole);
    $this->getJson('/api/v1/inventory/items')->assertForbidden();
});

it('stkapi creates, shows and edits items with the right permissions', function () {
    $b = Tenant::factory()->create();
    $theirs = stkapiItem(stkapiUser($b), 'THEIRS');

    $a = Tenant::factory()->create();
    $manager = stkapiUser($a);
    $sales = stkapiUser($a, 'sales');
    $viewer = stkapiUser($a, 'viewer');

    Sanctum::actingAs($sales);
    $this->postJson('/api/v1/inventory/items', ['sku' => 'x1', 'name' => 'X'])->assertForbidden();
    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/inventory/items', ['sku' => 'x1', 'name' => 'X'])->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson('/api/v1/inventory/items', [])->assertStatus(422)->assertJsonValidationErrors(['sku', 'name']);
    $this->postJson('/api/v1/inventory/items', ['sku' => '!!', 'name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->postJson('/api/v1/inventory/items', ['sku' => 'x1', 'name' => 'X', 'reorder_level' => '-2'])->assertStatus(422)->assertJsonValidationErrors('reorder_level');

    $id = (int) $this->postJson('/api/v1/inventory/items', ['sku' => 'bolt-1', 'name' => 'Bolt', 'unit' => 'pcs', 'reorder_level' => 12.5])
        ->assertCreated()
        ->assertJsonPath('sku', 'BOLT-1')
        ->assertJsonPath('on_hand', '0.000')
        ->assertJsonPath('reorder_level', '12.500')
        ->assertJsonPath('is_active', true)
        ->assertJsonMissingPath('tenant_id')
        ->json('id');

    $this->postJson('/api/v1/inventory/items', ['sku' => 'BOLT-1', 'name' => 'Dup'])->assertStatus(422)->assertJsonValidationErrors('sku');

    $this->putJson("/api/v1/inventory/items/{$id}", ['sku' => 'BOLT-1', 'name' => 'Bolt zinc', 'unit' => 'box', 'reorder_level' => '3', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('name', 'Bolt zinc')
        ->assertJsonPath('unit', 'box')
        ->assertJsonPath('reorder_level', '3.000')
        ->assertJsonPath('is_active', false);
    $this->putJson("/api/v1/inventory/items/{$id}", ['sku' => 'CHANGED', 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->putJson("/api/v1/inventory/items/{$theirs}", ['sku' => 'THEIRS', 'name' => 'Hijack'])->assertNotFound();

    Sanctum::actingAs($viewer);
    $this->getJson("/api/v1/inventory/items/{$id}")->assertOk()->assertJsonPath('sku', 'BOLT-1')->assertJsonMissingPath('tenant_id');
    $this->getJson("/api/v1/inventory/items/{$theirs}")->assertNotFound();
    $this->getJson('/api/v1/inventory/items/abc')->assertNotFound();
    $this->putJson("/api/v1/inventory/items/{$id}", ['sku' => 'BOLT-1', 'name' => 'x'])->assertForbidden();
});

it('stkapi appends movements and serves the ledger newest first', function () {
    $b = Tenant::factory()->create();
    $theirs = stkapiItem(stkapiUser($b), 'THEIRS');

    $a = Tenant::factory()->create();
    $manager = stkapiUser($a);
    $sales = stkapiUser($a, 'sales');
    $viewer = stkapiUser($a, 'viewer');
    $id = stkapiItem($manager, 'BOLT', 'Bolt', '5');
    $post = fn (array $body) => $this->postJson("/api/v1/inventory/items/{$id}/movements", $body);

    Sanctum::actingAs($sales);
    $post(['type' => 'receipt', 'quantity' => '1'])->assertForbidden();

    Sanctum::actingAs($manager);
    $post([])->assertStatus(422)->assertJsonValidationErrors(['type', 'quantity']);
    $post(['type' => 'bogus', 'quantity' => '1'])->assertStatus(422)->assertJsonValidationErrors('type');
    $post(['type' => 'receipt', 'quantity' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('quantity');
    $post(['type' => 'receipt', 'quantity' => '0'])->assertStatus(422)->assertJsonValidationErrors('quantity');

    $post(['type' => 'receipt', 'quantity' => 10, 'reference' => 'PO-1'])
        ->assertCreated()
        ->assertJsonPath('type', 'receipt')
        ->assertJsonPath('quantity_delta', '10.000')
        ->assertJsonPath('balance_after', '10.000')
        ->assertJsonPath('reference', 'PO-1')
        ->assertJsonMissingPath('tenant_id');

    $post(['type' => 'issue', 'quantity' => '4.5'])->assertCreated()->assertJsonPath('quantity_delta', '-4.500')->assertJsonPath('balance_after', '5.500');
    $post(['type' => 'issue', 'quantity' => '6'])->assertStatus(422)->assertJsonValidationErrors('quantity'); // only 5.500 on hand
    $post(['type' => 'adjustment', 'quantity' => '-1'])->assertStatus(422)->assertJsonValidationErrors('note'); // reason required
    $post(['type' => 'adjustment', 'quantity' => '-1', 'note' => 'Damaged'])->assertCreated()->assertJsonPath('balance_after', '4.500');

    $this->getJson("/api/v1/inventory/items/{$id}")->assertOk()->assertJsonPath('on_hand', '4.500')->assertJsonPath('low_stock', true);

    Sanctum::actingAs($viewer);
    $this->getJson("/api/v1/inventory/items/{$id}/movements")
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.type', 'adjustment') // newest first
        ->assertJsonPath('data.0.balance_after', '4.500')
        ->assertJsonPath('data.2.type', 'receipt')
        ->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson("/api/v1/inventory/items/{$id}/movements?type=issue")->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/inventory/items/{$id}/movements?type=bogus")->assertStatus(422)->assertJsonValidationErrors('type');

    // Another tenant's item is invisible and untouchable.
    $this->getJson("/api/v1/inventory/items/{$theirs}/movements")->assertNotFound();
    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/inventory/items/{$theirs}/movements", ['type' => 'receipt', 'quantity' => '1'])->assertNotFound();

    stkapiSwitch($b);
    expect(DB::table('inventory_movements')->where('inventory_item_id', $theirs)->count())->toBe(0);
});
