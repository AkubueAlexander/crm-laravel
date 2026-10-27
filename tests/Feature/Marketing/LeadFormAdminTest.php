<?php

use App\Domain\Leads\Forms\LeadFormDsl;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** @return array{0: Tenant, 1: User} */
function lformActor(string $role = 'admin'): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $user];
}

/** @return array<string, mixed> */
function lformPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Website contact',
        'source' => 'website',
        'success_message' => 'Thanks, we will be in touch.',
        'is_active' => true,
        'fields' => [
            ['key' => 'first_name', 'label' => 'First name', 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'required' => true],
        ],
    ], $overrides);
}

function lformInsert(Tenant $tenant, string $name = 'Other form'): int
{
    return (int) DB::table('lead_forms')->insertGetId([
        'tenant_id' => $tenant->id,
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'name' => $name,
        'is_active' => true,
        'fields' => json_encode([['key' => 'first_name', 'label' => 'First name', 'required' => true], ['key' => 'email', 'label' => 'Email', 'required' => true]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('dsl validates the field list', function () {
    $good = [['key' => 'first_name', 'label' => 'First', 'required' => true], ['key' => 'phone', 'label' => 'Phone', 'required' => false]];

    expect(LeadFormDsl::errors($good))->toBe([])
        ->and(array_keys(LeadFormDsl::errors([])))->toBe(['fields'])
        ->and(array_keys(LeadFormDsl::errors('x')))->toBe(['fields'])
        ->and(array_keys(LeadFormDsl::errors([['key' => 'status', 'label' => 'Status', 'required' => true]])))->toBe(['fields.0.key'])
        ->and(array_keys(LeadFormDsl::errors([$good[0], $good[0]])))->toBe(['fields.1.key'])
        ->and(array_keys(LeadFormDsl::errors([['key' => 'first_name', 'label' => ' ', 'required' => true]])))->toBe(['fields.0.label'])
        ->and(array_keys(LeadFormDsl::errors([['key' => 'first_name', 'label' => 'First', 'required' => true]])))->toBe(['fields'])
        ->and(array_keys(LeadFormDsl::errors([['key' => 'first_name', 'label' => 'First', 'required' => false], ['key' => 'email', 'label' => 'Email', 'required' => true]])))->toBe(['fields']);

    $rules = LeadFormDsl::submissionRules(LeadFormDsl::normalize($good));

    expect(array_keys($rules))->toBe(['first_name', 'phone'])
        ->and($rules['first_name'][0])->toBe('required')
        ->and($rules['phone'][0])->toBe('nullable');
});

test('an administrator creates a form whose tenant, uuid and creator come from the server', function () {
    [$tenant, $admin] = lformActor('admin');

    $response = $this->actingAs($admin)
        ->postJson('/api/v1/lead-forms', lformPayload(['tenant_id' => 999999, 'uuid' => 'forged', 'created_by' => 1]))
        ->assertCreated();

    $uuid = $response->json('uuid');

    expect($uuid)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($response->json('public_path'))->toBe('/f/'.$uuid)
        ->and($response->json('fields'))->toEqual(lformPayload()['fields']);

    $row = DB::table('lead_forms')->where('id', $response->json('id'))->first();

    expect((int) $row->tenant_id)->toBe($tenant->id)
        ->and((int) $row->created_by)->toBe($admin->id)
        ->and($row->uuid)->toBe($uuid);
});

test('it validates the form and points field errors at the failing row', function () {
    [, $admin] = lformActor('admin');

    $this->actingAs($admin)->postJson('/api/v1/lead-forms', lformPayload(['name' => '']))
        ->assertStatus(422)->assertJsonValidationErrors(['name']);

    $this->actingAs($admin)->postJson('/api/v1/lead-forms', lformPayload(['fields' => [['key' => 'email', 'label' => 'Email', 'required' => true]]]))
        ->assertStatus(422)->assertJsonValidationErrors(['fields']);

    $this->actingAs($admin)->postJson('/api/v1/lead-forms', lformPayload(['fields' => [
        ['key' => 'first_name', 'label' => 'First', 'required' => true],
        ['key' => 'owner_id', 'label' => 'Owner', 'required' => false],
    ]]))->assertStatus(422)->assertJsonValidationErrors(['fields.1.key']);
});

test('form names are unique per tenant, ignoring case, but not across tenants', function () {
    [$tenant, $admin] = lformActor('admin');

    $this->actingAs($admin)->postJson('/api/v1/lead-forms', lformPayload(['name' => 'Contact us']))->assertCreated();
    $this->actingAs($admin)->postJson('/api/v1/lead-forms', lformPayload(['name' => 'CONTACT US']))
        ->assertStatus(422)->assertJsonValidationErrors(['name']);

    [, $otherAdmin] = lformActor('admin');
    $this->actingAs($otherAdmin)->postJson('/api/v1/lead-forms', lformPayload(['name' => 'Contact us']))->assertCreated();
});

test('every role can read forms but only administrators and managers can write', function () {
    foreach (['admin', 'manager'] as $role) {
        [, $user] = lformActor($role);
        $this->actingAs($user)->postJson('/api/v1/lead-forms', lformPayload())->assertCreated();
    }

    foreach (['sales', 'viewer'] as $role) {
        [$tenant, $user] = lformActor($role);
        $id = lformInsert($tenant);

        $this->actingAs($user)->getJson('/api/v1/lead-forms')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/lead-forms/catalog')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/lead-forms', lformPayload())->assertForbidden();
        $this->actingAs($user)->putJson("/api/v1/lead-forms/{$id}", lformPayload())->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/lead-forms/{$id}")->assertForbidden();
    }
});

test('it lists only the actors tenant and serves the catalog', function () {
    [$tenant, $admin] = lformActor('admin');
    $mine = lformInsert($tenant, 'Mine');

    [$other] = lformActor('admin');
    lformInsert($other, 'Theirs');

    // Back to the first tenant's session.
    $response = $this->actingAs($admin)->getJson('/api/v1/lead-forms')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toEqual([$mine]);

    $catalog = $this->actingAs($admin)->getJson('/api/v1/lead-forms/catalog')->assertOk();

    expect(collect($catalog->json('data'))->pluck('key')->all())->toBe(['first_name', 'last_name', 'email', 'phone', 'company'])
        ->and($catalog->json('max_fields'))->toBe(5);
});

test('it updates and deletes a form, and never touches another tenants form', function () {
    [$tenant, $admin] = lformActor('admin');
    $id = lformInsert($tenant, 'Original');
    $uuidBefore = DB::table('lead_forms')->where('id', $id)->value('uuid');

    $this->actingAs($admin)->putJson("/api/v1/lead-forms/{$id}", lformPayload(['name' => 'Renamed', 'is_active' => false]))
        ->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('is_active', false);

    expect(DB::table('lead_forms')->where('id', $id)->value('uuid'))->toBe($uuidBefore);

    [$other] = lformActor('admin');
    $foreign = lformInsert($other, 'Foreign');

    $this->actingAs($admin)->getJson("/api/v1/lead-forms/{$foreign}")->assertNotFound();
    $this->actingAs($admin)->putJson("/api/v1/lead-forms/{$foreign}", lformPayload())->assertNotFound();
    $this->actingAs($admin)->deleteJson("/api/v1/lead-forms/{$foreign}")->assertNotFound();

    expect(DB::table('lead_forms')->where('id', $foreign)->exists())->toBeTrue();

    $this->actingAs($admin)->deleteJson("/api/v1/lead-forms/{$id}")->assertNoContent();

    expect(DB::table('lead_forms')->where('id', $id)->exists())->toBeFalse();
});