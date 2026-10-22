<?php

use App\Domain\Deals\States\Lead as DealLeadState;
use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
});

/** Creates a user on $tenant with the given abilities and acts as them. */
function convertActor(Tenant $tenant, array $abilities = ['leads.view', 'leads.convert']): User
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach ($abilities as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $user->forgetCachedPermissions();

    Sanctum::actingAs($user);

    return $user;
}

/** A convertible lead on $tenant; every field the action reads is explicit. */
function convertibleLead(Tenant $tenant, array $attrs = []): Lead
{
    TenantContext::set($tenant);

    return Lead::factory()->create(array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'phone' => '555-0100',
        'company' => 'Analytical Engines Ltd',
        'status' => 'qualified',
        'owner_id' => null,
    ], $attrs));
}

test('converting a lead creates a contact, an account and a deal and marks the lead converted', function () {
    convertActor($this->tenantA);
    $owner = User::factory()->create(['tenant_id' => $this->tenantA->id]);
    $lead = convertibleLead($this->tenantA, ['owner_id' => $owner->id]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    $response->assertJsonPath('status', 'converted');

    $contactId = $response->json('converted_contact_id');
    $accountId = $response->json('converted_account_id');
    $dealId = $response->json('converted_deal_id');

    expect($contactId)->not->toBeNull();
    expect($accountId)->not->toBeNull();
    expect($dealId)->not->toBeNull();

    $this->assertDatabaseHas('accounts', [
        'id' => $accountId,
        'name' => 'Analytical Engines Ltd',
        'owner_id' => $owner->id,
        'tenant_id' => $this->tenantA->id,
    ]);
    $this->assertDatabaseHas('contacts', [
        'id' => $contactId,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'account_id' => $accountId,
        'tenant_id' => $this->tenantA->id,
    ]);
    $this->assertDatabaseHas('deals', [
        'id' => $dealId,
        'name' => 'Analytical Engines Ltd - Ada Lovelace',
        'account_id' => $accountId,
        'contact_id' => $contactId,
        'owner_id' => $owner->id,
        'tenant_id' => $this->tenantA->id,
    ]);
    $this->assertDatabaseHas('leads', [
        'id' => $lead->id,
        'status' => 'converted',
        'converted_contact_id' => $contactId,
        'converted_account_id' => $accountId,
        'converted_deal_id' => $dealId,
    ]);
    expect(DB::table('leads')->where('id', $lead->id)->value('converted_at'))->not->toBeNull();
});

test('the deal created by conversion starts in the default lead stage', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $dealId = $this->postJson("/api/v1/leads/{$lead->id}/convert")->json('converted_deal_id');

    $deal = Deal::withoutGlobalScopes()->findOrFail($dealId);

    expect($deal->state)->toBeInstanceOf(DealLeadState::class);
    expect($deal->lock_version)->toBe(0);
});

test('an unowned lead produces a deal owned by the user who converted it', function () {
    $actor = convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['owner_id' => null]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    $this->assertDatabaseHas('deals', [
        'id' => $response->json('converted_deal_id'),
        'owner_id' => $actor->id,
    ]);
    $this->assertDatabaseHas('accounts', [
        'id' => $response->json('converted_account_id'),
        'owner_id' => null,
    ]);
});

test('optional deal fields shape the created deal', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert", [
        'deal_name' => 'Big Rollout',
        'amount' => '12500.50',
        'expected_close_date' => '2026-12-31',
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('deals', [
        'id' => $response->json('converted_deal_id'),
        'name' => 'Big Rollout',
        'amount' => '12500.50',
        'expected_close_date' => '2026-12-31',
    ]);
});

test('an existing account with the same company name is reused, case-insensitively', function () {
    convertActor($this->tenantA);
    $existing = Account::create(['name' => 'ANALYTICAL ENGINES LTD']);
    $lead = convertibleLead($this->tenantA, ['company' => 'Analytical Engines Ltd']);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    expect($response->json('converted_account_id'))->toBe($existing->id);
    expect(DB::table('accounts')->where('tenant_id', $this->tenantA->id)->count())->toBe(1);
    $this->assertDatabaseHas('contacts', [
        'id' => $response->json('converted_contact_id'),
        'account_id' => $existing->id,
    ]);
});

test('a lead without a company converts without creating an account', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['company' => null]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    expect($response->json('converted_account_id'))->toBeNull();
    $this->assertDatabaseHas('contacts', [
        'id' => $response->json('converted_contact_id'),
        'account_id' => null,
    ]);
    $this->assertDatabaseHas('deals', [
        'id' => $response->json('converted_deal_id'),
        'name' => 'Ada Lovelace',
        'account_id' => null,
        'contact_id' => $response->json('converted_contact_id'),
    ]);
    expect(DB::table('accounts')->count())->toBe(0);
});

test('an account with the same name in another tenant is never reused', function () {
    TenantContext::set($this->tenantB);
    $foreign = Account::create(['name' => 'Analytical Engines Ltd']);

    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertOk();
    expect($response->json('converted_account_id'))->not->toBe($foreign->id);
    $this->assertDatabaseHas('accounts', [
        'id' => $response->json('converted_account_id'),
        'tenant_id' => $this->tenantA->id,
    ]);
});

test('converting an already converted lead returns 409 and creates nothing', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['status' => 'converted', 'converted_at' => now()]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertStatus(409);
    $response->assertJsonPath('code', 'already_converted');
    $response->assertJsonPath('current.status', 'converted');
    expect(DB::table('contacts')->count())->toBe(0);
    expect(DB::table('deals')->count())->toBe(0);
    expect(DB::table('accounts')->count())->toBe(0);
});

test('a lead without a last name cannot be converted and nothing is created', function (?string $lastName) {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA, ['last_name' => $lastName]);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'missing_last_name');
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'qualified']);
    expect(DB::table('contacts')->count())->toBe(0);
    expect(DB::table('deals')->count())->toBe(0);
    expect(DB::table('accounts')->count())->toBe(0);
})->with([
    'null last name' => [null],
    'blank last name' => ['   '],
]);

test('a user without leads.convert cannot convert a lead', function () {
    convertActor($this->tenantA, abilities: ['leads.view']);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert");

    $response->assertForbidden();
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'qualified']);
    expect(DB::table('contacts')->count())->toBe(0);
});

test('a user cannot convert another tenant lead', function () {
    $foreignLead = convertibleLead($this->tenantB);
    convertActor($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$foreignLead->id}/convert");

    $response->assertNotFound();
    $this->assertDatabaseHas('leads', ['id' => $foreignLead->id, 'status' => 'qualified']);
});

test('an invalid deal amount is rejected and the lead stays unconverted', function () {
    convertActor($this->tenantA);
    $lead = convertibleLead($this->tenantA);

    $response = $this->postJson("/api/v1/leads/{$lead->id}/convert", ['amount' => -5]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('amount');
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'qualified']);
});
