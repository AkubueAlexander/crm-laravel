<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function accountPickerActor(Tenant $tenant, array $permissions = ['accounts.view']): User
{
    TenantContext::set($tenant);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);
    $registrar->forgetCachedPermissions();

    return $user;
}

function accountPickerAccountFor(Tenant $tenant, array $attributes = []): Account
{
    TenantContext::set($tenant);

    return Account::factory()->create($attributes);
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    Sanctum::actingAs(accountPickerActor($this->tenantA));
});

it('lists only current-tenant, non-deleted accounts ordered by name', function () {
    accountPickerAccountFor($this->tenantA, ['name' => 'Bravo Ltd']);
    accountPickerAccountFor($this->tenantA, ['name' => 'Alpha Inc']);
    accountPickerAccountFor($this->tenantA, ['name' => 'Gone Co'])->delete();
    accountPickerAccountFor($this->tenantB, ['name' => 'Zeta Corp']);

    $this->getJson('/api/v1/accounts/options')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Inc')
        ->assertJsonPath('data.1.name', 'Bravo Ltd');
});

it('exposes only id and name', function () {
    accountPickerAccountFor($this->tenantA, ['name' => 'Alpha Inc', 'industry' => 'Software']);

    $row = $this->getJson('/api/v1/accounts/options')->assertOk()->json('data.0');

    expect(array_keys($row))->toBe(['id', 'name']);
});

it('forbids the options endpoint without accounts.view', function () {
    Sanctum::actingAs(accountPickerActor($this->tenantA, ['accounts.create']));

    $this->getJson('/api/v1/accounts/options')->assertForbidden();
});