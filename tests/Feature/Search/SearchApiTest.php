<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

const SRCH_ALL = ['leads.view', 'contacts.view', 'accounts.view', 'deals.view'];

function srchSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

/** @return array{0: Tenant, 1: User} */
function srchTenant(string $slug, array $perms = SRCH_ALL): array
{
    $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
    srchSwitch($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    foreach ($perms as $name) {
        Permission::findOrCreate($name);
        $user->givePermissionTo($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $user];
}

/** One lead, contact, account and deal containing $word, inside $tenant. */
function srchSeed(Tenant $tenant, User $owner, string $word): void
{
    srchSwitch($tenant);

    Lead::create(['first_name' => $word, 'last_name' => 'Lead', 'email' => strtolower($word) . '-lead@example.test', 'company' => 'LeadCo']);
    Contact::create(['first_name' => $word, 'last_name' => 'Contact', 'email' => strtolower($word) . '-contact@example.test']);
    Account::create(['name' => $word . ' Industries']);
    Deal::create(['name' => $word . ' Deal', 'owner_id' => $owner->id, 'amount' => 0, 'expected_close_date' => now()->addMonth()->toDateString()]);
}

function srchGet($test, string $q)
{
    return $test->getJson('/api/v1/search?q=' . urlencode($q));
}

it('returns grouped matches across leads, contacts, accounts and deals', function () {
    [$tenant, $user] = srchTenant('srch-a');
    srchSeed($tenant, $user, 'Zebra');
    Sanctum::actingAs($user);

    srchGet($this, 'zebra')
        ->assertOk()
        ->assertJsonPath('query', 'zebra')
        ->assertJsonCount(1, 'data.leads')
        ->assertJsonCount(1, 'data.contacts')
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonCount(1, 'data.deals')
        ->assertJsonPath('data.accounts.0.label', 'Zebra Industries')
        ->assertJsonPath('data.deals.0.label', 'Zebra Deal')
        ->assertJsonMissingPath('data.leads.0.tenant_id');
});

it('never returns records from another tenant', function () {
    [$mine, $me] = srchTenant('srch-b1');
    [$other, $them] = srchTenant('srch-b2');

    srchSeed($other, $them, 'Quokka');
    srchSwitch($mine);
    Sanctum::actingAs($me);

    srchGet($this, 'quokka')
        ->assertOk()
        ->assertJsonCount(0, 'data.leads')
        ->assertJsonCount(0, 'data.contacts')
        ->assertJsonCount(0, 'data.accounts')
        ->assertJsonCount(0, 'data.deals');
});

it('omits groups the actor lacks the view permission for', function () {
    [$tenant, $user] = srchTenant('srch-c', ['leads.view', 'accounts.view']);
    srchSeed($tenant, $user, 'Walrus');
    Sanctum::actingAs($user);

    srchGet($this, 'walrus')
        ->assertOk()
        ->assertJsonCount(1, 'data.leads')
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonMissingPath('data.contacts')
        ->assertJsonMissingPath('data.deals');
});

it('returns an empty object for an actor with no view permissions', function () {
    [$tenant, $user] = srchTenant('srch-d', []);
    srchSeed($tenant, $user, 'Ibex');
    Sanctum::actingAs($user);

    $res = srchGet($this, 'ibex')->assertOk();
    expect($res->json('data'))->toBe([]);
    expect($res->getContent())->toContain('"data":{}');
});

it('rejects missing or too-short queries', function () {
    [, $user] = srchTenant('srch-e');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/search')->assertStatus(422);
    srchGet($this, 'a')->assertStatus(422);
    srchGet($this, '   ')->assertStatus(422);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/search?q=zebra')->assertUnauthorized();
});

it('excludes soft-deleted records', function () {
    [$tenant, $user] = srchTenant('srch-f');
    srchSeed($tenant, $user, 'Lemur');
    Account::query()->where('name', 'like', 'Lemur%')->first()->delete();
    Sanctum::actingAs($user);

    srchGet($this, 'lemur')
        ->assertOk()
        ->assertJsonCount(0, 'data.accounts')
        ->assertJsonCount(1, 'data.leads');
});

it('caps each group at five results', function () {
    [$tenant, $user] = srchTenant('srch-g', ['accounts.view']);
    srchSwitch($tenant);
    foreach (range(1, 7) as $i) {
        Account::create(['name' => "Otter {$i}"]);
    }
    Sanctum::actingAs($user);

    srchGet($this, 'otter')->assertOk()->assertJsonCount(5, 'data.accounts');
});

it('ranks prefix matches before substring matches', function () {
    [$tenant, $user] = srchTenant('srch-h', ['accounts.view']);
    srchSwitch($tenant);
    Account::create(['name' => 'The Falcon Group']);
    Account::create(['name' => 'Falcon Works']);
    Sanctum::actingAs($user);

    srchGet($this, 'falcon')
        ->assertOk()
        ->assertJsonPath('data.accounts.0.label', 'Falcon Works')
        ->assertJsonPath('data.accounts.1.label', 'The Falcon Group');
});

it('treats LIKE wildcards in the query literally', function () {
    [$tenant, $user] = srchTenant('srch-i', ['accounts.view']);
    srchSwitch($tenant);
    Account::create(['name' => 'Plain Company']);
    Sanctum::actingAs($user);

    srchGet($this, '%l')->assertOk()->assertJsonCount(0, 'data.accounts');
    srchGet($this, 'p_ain')->assertOk()->assertJsonCount(0, 'data.accounts');
});

it('matches leads by company and email', function () {
    [$tenant, $user] = srchTenant('srch-j', ['leads.view']);
    srchSwitch($tenant);
    Lead::create(['first_name' => 'Ana', 'last_name' => 'Kim', 'email' => 'ana@bluewhale.test', 'company' => 'Bluewhale Ltd']);
    Sanctum::actingAs($user);

    srchGet($this, 'bluewhale')->assertOk()->assertJsonCount(1, 'data.leads');
    srchGet($this, 'ana kim')->assertOk()->assertJsonCount(1, 'data.leads');
});
