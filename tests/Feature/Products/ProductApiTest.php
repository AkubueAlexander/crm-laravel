<?php

use App\Domain\Products\Actions\SaveProductAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function prodapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function prodapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    prodapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $over */
function prodapiSeed(User $actor, string $sku, string $name, array $over = []): Product
{
    prodapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(SaveProductAction::class)->execute($actor, null, array_merge([
        'sku' => $sku,
        'name' => $name,
        'unit_price' => '10.00',
        'tax_category' => 'standard',
        'is_active' => true,
        'variants' => [],
    ], $over));
}

/** @param array<string, mixed> $over */
function prodapiBody(array $over = []): array
{
    return array_merge([
        'sku' => 'WID-001',
        'name' => 'Widget',
        'description' => 'A widget',
        'unit_price' => '19.90',
        'tax_category' => 'standard',
        'is_active' => true,
        'variants' => [],
    ], $over);
}

it('prodapi forbids access without the catalog permissions', function () {
    $tenant = Tenant::factory()->create();
    $none = prodapiUser($tenant, null);
    $sales = prodapiUser($tenant, 'sales');
    $product = prodapiSeed(prodapiUser($tenant), 'P1', 'One');

    Sanctum::actingAs($none);
    $this->getJson('/api/v1/products')->assertForbidden();
    $this->getJson("/api/v1/products/{$product->id}")->assertForbidden();

    // Sales can read, not write.
    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/products')->assertOk();
    $this->getJson("/api/v1/products/{$product->id}")->assertOk();
    $this->postJson('/api/v1/products', prodapiBody())->assertForbidden();
    $this->putJson("/api/v1/products/{$product->id}", prodapiBody())->assertForbidden();
    $this->deleteJson("/api/v1/products/{$product->id}")->assertForbidden();
});

it('prodapi lists only the actors tenant with the whitelisted shape', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = prodapiUser($a);
    $managerB = prodapiUser($b);
    prodapiSeed($managerA, 'A1', 'Alpha', ['variants' => [['sku' => 'A1-X', 'name' => 'X']]]);
    prodapiSeed($managerB, 'B1', 'Bravo');

    Sanctum::actingAs($managerA);
    $response = $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'sku', 'name', 'description', 'unit_price', 'tax_category', 'is_active', 'variants_count', 'created_at', 'updated_at']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('meta.total', 1)
        ->assertJsonMissingPath('data.0.tenant_id')
        ->assertJsonMissingPath('data.0.deleted_at');

    expect($response->json('data.0.sku'))->toBe('A1');
    expect($response->json('data.0.variants_count'))->toBe(1);
});

it('prodapi searches, filters and sorts', function () {
    $tenant = Tenant::factory()->create();
    $manager = prodapiUser($tenant);
    prodapiSeed($manager, 'CHAIR', 'Office chair', ['unit_price' => '30']);
    prodapiSeed($manager, 'DESK', 'Standing desk', ['unit_price' => '5', 'tax_category' => 'reduced', 'variants' => [['sku' => 'DESK-OAK', 'name' => 'Oak']]]);
    prodapiSeed($manager, 'LAMP', 'Lamp', ['unit_price' => '10', 'is_active' => false]);

    Sanctum::actingAs($manager);
    $skus = fn (string $query) => collect($this->getJson("/api/v1/products?{$query}")->assertOk()->json('data'))->pluck('sku')->all();

    expect($skus('q=chair'))->toBe(['CHAIR']);
    expect($skus('q=oak'))->toBe(['DESK']); // matches a variant SKU
    expect($skus('q=%25'))->toBe([]); // a literal percent sign, not a wildcard
    expect($skus('status=inactive'))->toBe(['LAMP']);
    expect($skus('status=active&sort=sku'))->toBe(['CHAIR', 'DESK']);
    expect($skus('tax_category=reduced'))->toBe(['DESK']);
    expect($skus('sort=unit_price&direction=desc'))->toBe(['CHAIR', 'LAMP', 'DESK']);

    $this->getJson('/api/v1/products?sort=tenant_id')->assertStatus(422)->assertJsonValidationErrors('sort');
});

it('prodapi creates a product with variants and returns the resource', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(prodapiUser($tenant));

    $this->postJson('/api/v1/products', prodapiBody(['variants' => [
        ['sku' => 'WID-L', 'name' => 'Large', 'unit_price' => '24.5'],
        ['sku' => 'WID-S', 'name' => 'Small'],
    ]]))
        ->assertCreated()
        ->assertJsonPath('sku', 'WID-001')
        ->assertJsonPath('unit_price', '19.90')
        ->assertJsonPath('variants.0.unit_price', '24.50')
        ->assertJsonPath('variants.1.unit_price', null)
        ->assertJsonMissingPath('tenant_id');

    prodapiSwitch($tenant);
    expect(Product::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('prodapi validates input and maps SKU clashes to 422', function () {
    $tenant = Tenant::factory()->create();
    $manager = prodapiUser($tenant);
    prodapiSeed($manager, 'TAKEN', 'Taken');
    Sanctum::actingAs($manager);

    $this->postJson('/api/v1/products', [])->assertStatus(422)->assertJsonValidationErrors(['sku', 'name', 'unit_price', 'tax_category', 'variants']);
    $this->postJson('/api/v1/products', prodapiBody(['unit_price' => '-1']))->assertStatus(422)->assertJsonValidationErrors('unit_price');
    $this->postJson('/api/v1/products', prodapiBody(['tax_category' => 'luxury']))->assertStatus(422)->assertJsonValidationErrors('tax_category');
    $this->postJson('/api/v1/products', prodapiBody(['sku' => 'taken']))->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->postJson('/api/v1/products', prodapiBody(['variants' => [['sku' => '', 'name' => 'x']]]))->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
    $this->postJson('/api/v1/products', prodapiBody(['sku' => 'NEW', 'variants' => [['sku' => 'taken', 'name' => 'x']]]))->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
});

it('prodapi shows and updates a product, replacing its variants', function () {
    $tenant = Tenant::factory()->create();
    $manager = prodapiUser($tenant);
    $product = prodapiSeed($manager, 'P1', 'One', ['variants' => [['sku' => 'P1-A', 'name' => 'A'], ['sku' => 'P1-B', 'name' => 'B']]]);
    $keep = $product->variants[0]->id;
    Sanctum::actingAs($manager);

    $this->getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonCount(2, 'variants');

    $this->putJson("/api/v1/products/{$product->id}", prodapiBody([
        'sku' => 'P1',
        'name' => 'One v2',
        'variants' => [['id' => $keep, 'sku' => 'P1-A2', 'name' => 'A2'], ['sku' => 'P1-C', 'name' => 'C']],
    ]))
        ->assertOk()
        ->assertJsonPath('name', 'One v2')
        ->assertJsonCount(2, 'variants')
        ->assertJsonPath('variants.0.id', $keep)
        ->assertJsonPath('variants.0.sku', 'P1-A2')
        ->assertJsonPath('variants.1.sku', 'P1-C');

    // `variants` must be present on update: omitting it must not wipe the variants.
    $body = prodapiBody(['sku' => 'P1']);
    unset($body['variants']);
    $this->putJson("/api/v1/products/{$product->id}", $body)->assertStatus(422)->assertJsonValidationErrors('variants');
});

it('prodapi never reads or changes another tenants product', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = prodapiUser($a);
    $theirs = prodapiSeed(prodapiUser($b), 'B1', 'Bravo');

    Sanctum::actingAs($managerA);
    $this->getJson("/api/v1/products/{$theirs->id}")->assertNotFound();
    $this->putJson("/api/v1/products/{$theirs->id}", prodapiBody())->assertNotFound();
    $this->deleteJson("/api/v1/products/{$theirs->id}")->assertNotFound();

    prodapiSwitch($b);
    expect(Product::query()->where('tenant_id', $b->id)->count())->toBe(1);
});

it('prodapi deletes a product (204) and it disappears from the list', function () {
    $tenant = Tenant::factory()->create();
    $manager = prodapiUser($tenant);
    $product = prodapiSeed($manager, 'P1', 'One');
    Sanctum::actingAs($manager);

    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
    $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
});
