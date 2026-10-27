<?php

use App\Domain\Products\Actions\DeleteProductAction;
use App\Domain\Products\Actions\SaveProductAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function prodactSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function prodactActor(Tenant $tenant, string $role = 'manager'): User
{
    prodactSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $over */
function prodactData(array $over = []): array
{
    return array_merge([
        'sku' => 'WID-001',
        'name' => 'Widget',
        'description' => null,
        'unit_price' => '19.90',
        'tax_category' => 'standard',
        'is_active' => true,
        'variants' => [],
    ], $over);
}

it('prodact gives the catalog permissions to the right roles', function () {
    expect(RoleCatalog::permissionsFor('admin'))->toContain('products.view', 'products.manage');
    expect(RoleCatalog::permissionsFor('manager'))->toContain('products.view', 'products.manage');
    expect(RoleCatalog::permissionsFor('sales'))->toContain('products.view')->not->toContain('products.manage');
    expect(RoleCatalog::permissionsFor('viewer'))->toContain('products.view')->not->toContain('products.manage');
});

it('prodact creates a product with variants for the actors tenant', function () {
    $tenant = Tenant::factory()->create();
    $actor = prodactActor($tenant);

    $product = app(SaveProductAction::class)->execute($actor, null, prodactData([
        'sku' => ' wid-1 ',
        'variants' => [
            ['sku' => 'WID-1-L', 'name' => 'Large', 'unit_price' => '24.5'],
            ['sku' => 'WID-1-S', 'name' => 'Small'],
        ],
    ]));

    expect((int) $product->tenant_id)->toBe($tenant->id);
    expect($product->sku)->toBe('wid-1');
    expect($product->unit_price)->toBe('19.90');
    expect($product->variants)->toHaveCount(2);
    expect($product->variants[0]->unit_price)->toBe('24.50');
    expect($product->variants[1]->unit_price)->toBeNull();
    expect($product->variants[1]->sort_order)->toBe(1);
});

it('prodact rejects duplicate SKUs per tenant ignoring case, but allows them across tenants', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorA = prodactActor($a);
    $actorB = prodactActor($b);
    $save = app(SaveProductAction::class);

    prodactSwitch($a);
    $save->execute($actorA, null, prodactData(['sku' => 'DUP', 'variants' => [['sku' => 'DUP-V', 'name' => 'V']]]));

    expect(fn () => $save->execute($actorA, null, prodactData(['sku' => 'dup', 'name' => 'Other'])))->toThrow(ValidationException::class);
    // A variant SKU may not equal a product SKU, nor another variant's SKU.
    expect(fn () => $save->execute($actorA, null, prodactData(['sku' => 'dup-v', 'name' => 'Other'])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actorA, null, prodactData(['sku' => 'NEW', 'variants' => [['sku' => 'dup', 'name' => 'x']]])))->toThrow(ValidationException::class);
    // Duplicate inside one payload.
    expect(fn () => $save->execute($actorA, null, prodactData(['sku' => 'NEW2', 'variants' => [['sku' => 'q', 'name' => 'x'], ['sku' => 'Q', 'name' => 'y']]])))->toThrow(ValidationException::class);

    prodactSwitch($b);
    $other = $save->execute($actorB, null, prodactData(['sku' => 'DUP']));
    expect($other->sku)->toBe('DUP');
});

it('prodact update syncs variants: update by id, add new, remove absent', function () {
    $tenant = Tenant::factory()->create();
    $actor = prodactActor($tenant);
    $save = app(SaveProductAction::class);

    $product = $save->execute($actor, null, prodactData(['variants' => [
        ['sku' => 'A', 'name' => 'A'],
        ['sku' => 'B', 'name' => 'B'],
    ]]));
    $a = $product->variants[0];

    $updated = $save->execute($actor, $product->id, prodactData([
        'name' => 'Widget 2',
        'variants' => [
            ['id' => $a->id, 'sku' => 'A2', 'name' => 'A renamed', 'unit_price' => '5'],
            ['sku' => 'C', 'name' => 'C'],
        ],
    ]));

    expect($updated->name)->toBe('Widget 2');
    expect($updated->variants->pluck('sku')->all())->toBe(['A2', 'C']);
    expect($updated->variants[0]->id)->toBe($a->id);
    expect($updated->variants[0]->unit_price)->toBe('5.00');
    expect(ProductVariant::query()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('prodact refuses a variant id that belongs to another product or tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorA = prodactActor($a);
    $actorB = prodactActor($b);
    $save = app(SaveProductAction::class);

    prodactSwitch($b);
    $theirs = $save->execute($actorB, null, prodactData(['sku' => 'B1', 'variants' => [['sku' => 'B1-V', 'name' => 'V']]]));

    prodactSwitch($a);
    $mine = $save->execute($actorA, null, prodactData(['sku' => 'A1']));

    expect(fn () => $save->execute($actorA, $mine->id, prodactData(['sku' => 'A1', 'variants' => [
        ['id' => $theirs->variants[0]->id, 'sku' => 'X', 'name' => 'x'],
    ]])))->toThrow(ValidationException::class);

    // And another tenant's product is a 404.
    expect(fn () => $save->execute($actorA, $theirs->id, prodactData(['sku' => 'Z'])))->toThrow(ModelNotFoundException::class);
});

it('prodact soft-deletes, removes variants and frees the SKU', function () {
    $tenant = Tenant::factory()->create();
    $actor = prodactActor($tenant);
    $save = app(SaveProductAction::class);

    $product = $save->execute($actor, null, prodactData(['variants' => [['sku' => 'V1', 'name' => 'V']]]));

    app(DeleteProductAction::class)->execute($actor, $product->id);

    expect(Product::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(Product::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(ProductVariant::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $again = $save->execute($actor, null, prodactData(['variants' => [['sku' => 'V1', 'name' => 'V']]]));
    expect($again->sku)->toBe('WID-001');

    expect(fn () => app(DeleteProductAction::class)->execute($actor, 999999))->toThrow(ModelNotFoundException::class);
});

it('prodact requires products.manage', function () {
    $tenant = Tenant::factory()->create();
    $sales = prodactActor($tenant, 'sales');

    expect(fn () => app(SaveProductAction::class)->execute($sales, null, prodactData()))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeleteProductAction::class)->execute($sales, 1))->toThrow(AuthorizationException::class);
});

it('prodact re-validates fields server-side', function () {
    $tenant = Tenant::factory()->create();
    $actor = prodactActor($tenant);
    $save = app(SaveProductAction::class);

    expect(fn () => $save->execute($actor, null, prodactData(['tax_category' => 'luxury'])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, prodactData(['unit_price' => '-1'])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, prodactData(['unit_price' => 'abc'])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, prodactData(['name' => '  '])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, prodactData(['variants' => [['sku' => 'v', 'name' => 'v', 'unit_price' => '-5']]])))->toThrow(ValidationException::class);

    expect(Product::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
