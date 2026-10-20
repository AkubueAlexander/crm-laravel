<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
});

/** Creates a user on $tenant with the given leads.* abilities and acts as them. */
function leadActor(Tenant $tenant, array $abilities = ['leads.view', 'leads.create', 'leads.update', 'leads.delete']): User
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach ($abilities as $ability) {
        $permission = Permission::findOrCreate($ability, 'web');
        $user->givePermissionTo($permission);
    }

    $user->forgetCachedPermissions();

    Sanctum::actingAs($user);

    return $user;
}

/** Creates a Lead scoped to $tenant (bypassing the acting-as user's own tenant). */
function leadFor(Tenant $tenant, array $attrs = []): Lead
{
    TenantContext::set($tenant);

    return Lead::factory()->create($attrs);
}

test('a user can create a lead', function () {
    leadActor($this->tenantA);

    $response = $this->postJson('/api/v1/leads', [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'company' => 'Analytical Engines Ltd',
        'source' => 'web',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('first_name', 'Ada');
    $response->assertJsonPath('name', 'Ada Lovelace');
    $response->assertJsonPath('status', 'new');

    $this->assertDatabaseHas('leads', [
        'first_name' => 'Ada',
        'tenant_id' => $this->tenantA->id,
    ]);
});

test('creating a lead ignores a client-supplied tenant_id', function () {
    leadActor($this->tenantA);

    $response = $this->postJson('/api/v1/leads', [
        'first_name' => 'Ada',
        'tenant_id' => $this->tenantB->id,
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('leads', [
        'first_name' => 'Ada',
        'tenant_id' => $this->tenantA->id,
    ]);
    $this->assertDatabaseMissing('leads', [
        'first_name' => 'Ada',
        'tenant_id' => $this->tenantB->id,
    ]);
});

test('a lead cannot be created with status converted', function () {
    leadActor($this->tenantA);

    $response = $this->postJson('/api/v1/leads', [
        'first_name' => 'Ada',
        'status' => 'converted',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('status');
});

test('a lead cannot be updated to status converted', function () {
    leadActor($this->tenantA);
    $lead = leadFor($this->tenantA);

    $response = $this->patchJson("/api/v1/leads/{$lead->id}", [
        'status' => 'converted',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('status');
});

test('a lead can be updated to an assignable status', function () {
    leadActor($this->tenantA);
    $lead = leadFor($this->tenantA);

    $response = $this->patchJson("/api/v1/leads/{$lead->id}", [
        'status' => 'contacted',
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'contacted');
});

test('a user without leads.create cannot create a lead', function () {
    leadActor($this->tenantA, abilities: ['leads.view']);

    $response = $this->postJson('/api/v1/leads', [
        'first_name' => 'Ada',
    ]);

    $response->assertForbidden();
});

test('a user without leads.view cannot list leads', function () {
    leadActor($this->tenantA, abilities: ['leads.create']);

    $response = $this->getJson('/api/v1/leads');

    $response->assertForbidden();
});

test('a user cannot see another tenant leads in the index', function () {
    leadFor($this->tenantB, ['first_name' => 'Other Tenant Lead']);
    leadActor($this->tenantA);
    leadFor($this->tenantA, ['first_name' => 'Same Tenant Lead']);

    $response = $this->getJson('/api/v1/leads');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.first_name', 'Same Tenant Lead');
});

test('a user cannot fetch another tenant lead by id', function () {
    $foreignLead = leadFor($this->tenantB);
    leadActor($this->tenantA);

    $response = $this->getJson("/api/v1/leads/{$foreignLead->id}");

    $response->assertNotFound();
});

test('a user cannot update another tenant lead', function () {
    $foreignLead = leadFor($this->tenantB);
    leadActor($this->tenantA);

    $response = $this->patchJson("/api/v1/leads/{$foreignLead->id}", [
        'first_name' => 'Hijacked',
    ]);

    $response->assertNotFound();
});

test('a user can delete a lead', function () {
    leadActor($this->tenantA);
    $lead = leadFor($this->tenantA);

    $response = $this->deleteJson("/api/v1/leads/{$lead->id}");

    $response->assertNoContent();
    $this->assertSoftDeleted('leads', ['id' => $lead->id]);
});

test('the leads list can be filtered by status', function () {
    leadActor($this->tenantA);
    leadFor($this->tenantA, ['first_name' => 'New One', 'status' => 'new']);
    leadFor($this->tenantA, ['first_name' => 'Qualified One', 'status' => 'qualified']);

    $response = $this->getJson('/api/v1/leads?status=qualified');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.first_name', 'Qualified One');
});

test('the leads list can be filtered by the converted status', function () {
    leadActor($this->tenantA);
    leadFor($this->tenantA, ['first_name' => 'Still New', 'status' => 'new']);
    leadFor($this->tenantA, ['first_name' => 'Converted One', 'status' => 'converted', 'converted_at' => now()]);

    $response = $this->getJson('/api/v1/leads?status=converted');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.first_name', 'Converted One');
});

test('the leads list can be searched by name, company, or email', function () {
    leadActor($this->tenantA);
    leadFor($this->tenantA, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'company' => 'US Navy', 'email' => 'grace@navy.mil']);
    leadFor($this->tenantA, ['first_name' => 'Alan', 'last_name' => 'Turing', 'company' => 'Bletchley', 'email' => 'alan@bletchley.gov']);

    $response = $this->getJson('/api/v1/leads?q=hopper');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.first_name', 'Grace');
});

test('an invalid sort column is rejected', function () {
    leadActor($this->tenantA);

    $response = $this->getJson('/api/v1/leads?sort=email');

    $response->assertUnprocessable();
});