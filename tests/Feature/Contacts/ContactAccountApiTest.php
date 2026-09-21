<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function contactAccountActor(Tenant $tenant): User
{
    TenantContext::set($tenant);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    $permissions = ['contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);
    $registrar->forgetCachedPermissions();

    return $user;
}

function contactAccountFor(Tenant $tenant, array $attributes = []): Account
{
    TenantContext::set($tenant);

    return Account::factory()->create($attributes);
}

function contactAccountContactFor(Tenant $tenant, array $attributes = []): Contact
{
    TenantContext::set($tenant);

    return Contact::factory()->create($attributes);
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    Sanctum::actingAs(contactAccountActor($this->tenantA));
});

it('assigns a same-tenant account on create and on update', function () {
    $first = contactAccountFor($this->tenantA);
    $second = contactAccountFor($this->tenantA);

    $id = $this->postJson('/api/v1/contacts', [
        'last_name' => 'Lumbergh',
        'account_id' => $first->id,
    ])
        ->assertCreated()
        ->assertJsonPath('account_id', $first->id)
        ->json('id');

    $this->patchJson("/api/v1/contacts/{$id}", ['account_id' => $second->id])
        ->assertOk()
        ->assertJsonPath('account_id', $second->id);
});

it('rejects an account_id that belongs to another tenant', function () {
    $foreign = contactAccountFor($this->tenantB);

    $this->postJson('/api/v1/contacts', [
        'last_name' => 'Sneaky',
        'account_id' => $foreign->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id']);

    $contact = contactAccountContactFor($this->tenantA);

    $this->patchJson("/api/v1/contacts/{$contact->id}", ['account_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id']);

    expect(Contact::withoutGlobalScopes()->find($contact->id)->account_id)->toBeNull();
});

it('rejects a soft-deleted account_id', function () {
    $account = contactAccountFor($this->tenantA);
    $account->delete();

    $this->postJson('/api/v1/contacts', [
        'last_name' => 'Orphan',
        'account_id' => $account->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id']);
});

it('rejects a non-existent or non-integer account_id', function () {
    $this->postJson('/api/v1/contacts', ['last_name' => 'Ghost', 'account_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id']);

    $this->postJson('/api/v1/contacts', ['last_name' => 'Ghost', 'account_id' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id']);
});

it('clears the account when account_id is null and leaves it untouched when omitted', function () {
    $account = contactAccountFor($this->tenantA);
    $contact = contactAccountContactFor($this->tenantA, ['account_id' => $account->id, 'job_title' => 'Manager']);

    $this->patchJson("/api/v1/contacts/{$contact->id}", ['job_title' => 'Director'])
        ->assertOk()
        ->assertJsonPath('job_title', 'Director')
        ->assertJsonPath('account_id', $account->id);

    $this->patchJson("/api/v1/contacts/{$contact->id}", ['account_id' => null])
        ->assertOk()
        ->assertJsonPath('account_id', null)
        ->assertJsonPath('job_title', 'Director');
});