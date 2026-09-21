<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function ownerActor(Tenant $tenant): User
{
    TenantContext::set($tenant);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $permissions = ['accounts.view', 'accounts.create', 'accounts.update', 'accounts.delete'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);
    $registrar->forgetCachedPermissions();

    return $user;
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();

    Sanctum::actingAs(ownerActor($this->tenantA));
});

it('clears the owner when owner_id is sent as null', function () {
    TenantContext::set($this->tenantA);
    $owner = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $account = Account::factory()->create(['owner_id' => $owner->id]);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['owner_id' => null])
        ->assertOk()
        ->assertJsonPath('owner_id', null)
        ->assertJsonPath('owner', null);
});

it('leaves the owner untouched when owner_id is omitted from an update', function () {
    TenantContext::set($this->tenantA);
    $owner = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $account = Account::factory()->create(['owner_id' => $owner->id]);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['industry' => 'Finance'])
        ->assertOk()
        ->assertJsonPath('industry', 'Finance')
        ->assertJsonPath('owner_id', $owner->id);
});