<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Contact;
use App\Models\ContactMatchSettings;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function contactActor(Tenant $tenant, array $permissions = ['contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete']): User
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

function contactFor(Tenant $tenant, array $attributes = []): Contact
{
    TenantContext::set($tenant);

    return Contact::factory()->create($attributes);
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    Sanctum::actingAs(contactActor($this->tenantA));
});

it('creates a contact for the actor tenant and ignores a client-supplied tenant_id', function () {
    $this->postJson('/api/v1/contacts', [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
        'tenant_id' => $this->tenantB->id,
    ])
        ->assertCreated()
        ->assertJsonPath('full_name', 'Ada Lovelace')
        ->assertJsonMissingPath('tenant_id')
        ->assertJsonMissingPath('name_normalized')
        ->assertJsonMissingPath('phone_key');

    $stored = Contact::withoutGlobalScopes()->where('email', 'ada@example.test')->sole();

    expect($stored->tenant_id)->toEqual($this->tenantA->id);
});

it('rejects invalid input with validation errors', function () {
    $this->postJson('/api/v1/contacts', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['last_name', 'email']);
});

it('forbids actors that lack the permission', function () {
    Sanctum::actingAs(contactActor($this->tenantA, ['contacts.view']));

    $this->postJson('/api/v1/contacts', ['last_name' => 'Nope'])->assertForbidden();
});

it('updates only the supplied fields', function () {
    $contact = contactFor($this->tenantA, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'job_title' => 'Analyst']);

    $this->patchJson("/api/v1/contacts/{$contact->id}", ['job_title' => 'Engineer'])
        ->assertOk()
        ->assertJsonPath('job_title', 'Engineer')
        ->assertJsonPath('last_name', 'Lovelace');
});

it('soft deletes a contact', function () {
    $contact = contactFor($this->tenantA);

    $this->deleteJson("/api/v1/contacts/{$contact->id}")->assertNoContent();

    $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
});

it('lists only the current tenant contacts, paginated and sorted', function () {
    contactFor($this->tenantA, ['first_name' => 'Bea', 'last_name' => 'Brown']);
    contactFor($this->tenantA, ['first_name' => 'Al', 'last_name' => 'Adams']);
    contactFor($this->tenantB, ['first_name' => 'Zed', 'last_name' => 'Other']);

    $this->getJson('/api/v1/contacts?sort=last_name&direction=asc&per_page=10')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.last_name', 'Adams')
        ->assertJsonPath('data.1.last_name', 'Brown')
        ->assertJsonPath('meta.total', 2);
});

it('searches by name and rejects a non-whitelisted sort column', function () {
    contactFor($this->tenantA, ['first_name' => 'Al', 'last_name' => 'Adams']);
    contactFor($this->tenantA, ['first_name' => 'Bea', 'last_name' => 'Brown']);

    $this->getJson('/api/v1/contacts?q=adams')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.last_name', 'Adams');

    $this->getJson('/api/v1/contacts?sort=tenant_id')->assertUnprocessable();
});

it('returns 404 for another tenant contact on show, update and delete', function () {
    $foreign = contactFor($this->tenantB, ['last_name' => 'Secret']);

    $this->getJson("/api/v1/contacts/{$foreign->id}")->assertNotFound();
    $this->patchJson("/api/v1/contacts/{$foreign->id}", ['last_name' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/contacts/{$foreign->id}")->assertNotFound();

    $fresh = Contact::withoutGlobalScopes()->find($foreign->id);

    expect($fresh->last_name)->toBe('Secret')
        ->and($fresh->deleted_at)->toBeNull();
});

it('finds duplicates within the tenant only', function () {
    $mine = contactFor($this->tenantA, ['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@acme.test']);
    contactFor($this->tenantB, ['first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@acme.test']);

    $this->postJson('/api/v1/contacts/check-duplicates', [
        'first_name' => 'Jon',
        'last_name' => 'Smith',
        'email' => 'john@acme.test',
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.contact.id', $mine->id)
        ->assertJsonPath('data.0.score', 98)
        ->assertJsonPath('data.0.reasons', ['Similar name (97%)', 'Same email'])
        ->assertJsonPath('threshold', 75);
});

it('honours the per-tenant match threshold', function () {
    contactFor($this->tenantA, ['first_name' => 'John', 'last_name' => 'Smith']);

    TenantContext::set($this->tenantA);
    ContactMatchSettings::create(['match_threshold' => 100]);

    $this->postJson('/api/v1/contacts/check-duplicates', ['first_name' => 'Jon', 'last_name' => 'Smith'])
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('threshold', 100);
});

it('excludes the contact being edited from its own duplicate check', function () {
    $contact = contactFor($this->tenantA, ['first_name' => 'John', 'last_name' => 'Smith']);

    $this->postJson('/api/v1/contacts/check-duplicates', [
        'first_name' => 'John',
        'last_name' => 'Smith',
        'exclude_contact_id' => $contact->id,
    ])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
