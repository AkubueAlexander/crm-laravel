<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function accountActor(Tenant $tenant, array $permissions = ['accounts.view', 'accounts.create', 'accounts.update', 'accounts.delete']): User
{
    TenantContext::set($tenant);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $user->givePermissionTo($permissions);
    $registrar->forgetCachedPermissions();

    return $user;
}

function accountFor(Tenant $tenant, array $attributes = []): Account
{
    TenantContext::set($tenant);

    return Account::factory()->create($attributes);
}

function colleagueFor(Tenant $tenant): User
{
    TenantContext::set($tenant);

    return User::factory()->create(['tenant_id' => $tenant->id]);
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    Sanctum::actingAs(accountActor($this->tenantA));
});

it('creates an account for the actor tenant and ignores a client-supplied tenant_id', function () {
    $this->postJson('/api/v1/accounts', [
        'name' => 'Initech',
        'industry' => 'Software',
        'tenant_id' => $this->tenantB->id,
    ])
        ->assertCreated()
        ->assertJsonPath('name', 'Initech')
        ->assertJsonPath('contacts_count', 0)
        ->assertJsonMissingPath('tenant_id')
        ->assertJsonMissingPath('deleted_at');

    $stored = Account::withoutGlobalScopes()->where('name', 'Initech')->sole();

    expect($stored->tenant_id)->toEqual($this->tenantA->id);
});

it('rejects invalid input with validation errors', function () {
    $this->postJson('/api/v1/accounts', ['name' => '', 'website' => 'not a url'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'website']);
});

it('forbids actors that lack the permission', function () {
    Sanctum::actingAs(accountActor($this->tenantA, ['accounts.view']));

    $this->postJson('/api/v1/accounts', ['name' => 'Nope'])->assertForbidden();

    $account = accountFor($this->tenantA);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['name' => 'Nope'])->assertForbidden();
    $this->deleteJson("/api/v1/accounts/{$account->id}")->assertForbidden();
});

it('forbids listing and viewing without accounts.view', function () {
    $account = accountFor($this->tenantA);

    Sanctum::actingAs(accountActor($this->tenantA, ['accounts.create']));

    $this->getJson('/api/v1/accounts')->assertForbidden();
    $this->getJson("/api/v1/accounts/{$account->id}")->assertForbidden();
});

it('updates only the supplied fields', function () {
    $account = accountFor($this->tenantA, ['name' => 'Initech', 'industry' => 'Software']);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['industry' => 'Finance'])
        ->assertOk()
        ->assertJsonPath('industry', 'Finance')
        ->assertJsonPath('name', 'Initech');
});

it('soft deletes an account', function () {
    $account = accountFor($this->tenantA);

    $this->deleteJson("/api/v1/accounts/{$account->id}")->assertNoContent();

    $this->assertSoftDeleted('accounts', ['id' => $account->id]);
});

it('lists only the current tenant accounts, paginated, sorted, with contact counts', function () {
    $bravo = accountFor($this->tenantA, ['name' => 'Bravo Ltd']);
    $alpha = accountFor($this->tenantA, ['name' => 'Alpha Inc']);
    accountFor($this->tenantB, ['name' => 'Zeta Corp']);

    TenantContext::set($this->tenantA);
    Contact::factory()->count(2)->create(['account_id' => $alpha->id]);

    $this->getJson('/api/v1/accounts?sort=name&direction=asc&per_page=10')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Inc')
        ->assertJsonPath('data.0.contacts_count', 2)
        ->assertJsonPath('data.1.name', 'Bravo Ltd')
        ->assertJsonPath('data.1.contacts_count', 0)
        ->assertJsonPath('meta.total', 2);
});

it('searches by name and rejects a non-whitelisted sort column', function () {
    accountFor($this->tenantA, ['name' => 'Alpha Inc']);
    accountFor($this->tenantA, ['name' => 'Bravo Ltd']);

    $this->getJson('/api/v1/accounts?q=alph')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Inc');

    $this->getJson('/api/v1/accounts?sort=tenant_id')->assertUnprocessable();
});

it('treats LIKE wildcards in the search term literally', function () {
    accountFor($this->tenantA, ['name' => 'Alpha Inc']);

    $this->getJson('/api/v1/accounts?q=%25')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns 404 for another tenant account on show, update and delete', function () {
    $foreign = accountFor($this->tenantB, ['name' => 'Secret']);

    $this->getJson("/api/v1/accounts/{$foreign->id}")->assertNotFound();
    $this->patchJson("/api/v1/accounts/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/accounts/{$foreign->id}")->assertNotFound();

    $fresh = Account::withoutGlobalScopes()->find($foreign->id);

    expect($fresh->name)->toBe('Secret')
        ->and($fresh->deleted_at)->toBeNull();
});

it('accepts an owner from the same tenant and exposes it on the resource', function () {
    $colleague = colleagueFor($this->tenantA);

    $this->postJson('/api/v1/accounts', ['name' => 'Owned Co', 'owner_id' => $colleague->id])
        ->assertCreated()
        ->assertJsonPath('owner_id', $colleague->id)
        ->assertJsonPath('owner.id', $colleague->id);
});

it('rejects an owner_id that belongs to another tenant', function () {
    $outsider = colleagueFor($this->tenantB);

    $this->postJson('/api/v1/accounts', ['name' => 'Sneaky Co', 'owner_id' => $outsider->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['owner_id']);

    $account = accountFor($this->tenantA);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['owner_id' => $outsider->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['owner_id']);
});

it('leaves contacts untouched when their account is soft deleted', function () {
    $account = accountFor($this->tenantA);

    TenantContext::set($this->tenantA);
    $contact = Contact::factory()->create(['account_id' => $account->id]);

    $this->deleteJson("/api/v1/accounts/{$account->id}")->assertNoContent();

    $fresh = Contact::withoutGlobalScopes()->find($contact->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->deleted_at)->toBeNull()
        ->and($fresh->account_id)->toEqual($account->id);
});