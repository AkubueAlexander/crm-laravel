<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function optionsActor(Tenant $tenant, string $name = 'Zoe Actor', array $permissions = ['accounts.view']): User
{
    TenantContext::set($tenant);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id, 'name' => $name]);

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);
    $registrar->forgetCachedPermissions();

    return $user;
}

function optionsUserFor(Tenant $tenant, string $name): User
{
    TenantContext::set($tenant);

    return User::factory()->create(['tenant_id' => $tenant->id, 'name' => $name]);
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    Sanctum::actingAs(optionsActor($this->tenantA));
});

it('lists the current tenant users as id and name only, sorted by name', function () {
    $bob = optionsUserFor($this->tenantA, 'Bob');
    $alice = optionsUserFor($this->tenantA, 'Alice');
    optionsUserFor($this->tenantB, 'Mallory');

    $this->getJson('/api/v1/users/options')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Alice')
        ->assertJsonPath('data.0.id', $alice->id)
        ->assertJsonPath('data.1.name', 'Bob')
        ->assertJsonPath('data.1.id', $bob->id)
        ->assertJsonPath('data.2.name', 'Zoe Actor')
        ->assertJsonMissingPath('data.0.email')
        ->assertJsonMissingPath('data.0.tenant_id');
});

it('never leaks users from another tenant', function () {
    $outsider = optionsUserFor($this->tenantB, 'Mallory');

    $ids = collect($this->getJson('/api/v1/users/options')->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($outsider->id);
});

it('forbids actors without accounts.view', function () {
    Sanctum::actingAs(optionsActor($this->tenantA, 'Limited', ['accounts.create']));

    $this->getJson('/api/v1/users/options')->assertForbidden();
});