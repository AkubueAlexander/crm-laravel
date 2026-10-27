<?php

use App\Domain\Marketing\Actions\CreateSegmentAction;
use App\Domain\Marketing\Actions\DeleteSegmentAction;
use App\Domain\Marketing\Actions\UpdateSegmentAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Segment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function segapiUser(Tenant $tenant, string $role = 'admin'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $attributes */
function segapiSegment(Tenant $tenant, array $attributes = []): Segment
{
    TenantContext::set($tenant);

    $segment = new Segment();
    $segment->forceFill(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Segment '.Str::random(8),
        'match_mode' => 'all',
        'conditions' => [['field' => 'email', 'operator' => 'contains', 'value' => 'acme']],
    ], $attributes))->save();

    return $segment;
}

/** @param array<string, mixed> $attributes */
function segapiContact(Tenant $tenant, string $lastName, array $attributes = []): int
{
    return DB::table('contacts')->insertGetId(array_merge([
        'tenant_id' => $tenant->id,
        'first_name' => null,
        'last_name' => $lastName,
        'email' => null,
        'phone' => null,
        'job_title' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/** @param array<string, mixed> $overrides */
function segapiPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Acme people',
        'description' => 'Everyone at Acme',
        'match_mode' => 'all',
        'conditions' => [['field' => 'email', 'operator' => 'contains', 'value' => 'acme']],
    ], $overrides);
}

const SEGAPI_KEYS = ['id', 'name', 'description', 'match_mode', 'conditions', 'created_at', 'updated_at'];

it('gives every role read access and only administrators and managers write access', function () {
    $tenant = Tenant::factory()->create();
    $segment = segapiSegment($tenant, ['name' => 'Keep me']);

    $this->getJson('/api/v1/segments')->assertUnauthorized();
    $this->getJson('/api/v1/segments/fields')->assertUnauthorized();
    $this->postJson('/api/v1/segments/preview', segapiPayload())->assertUnauthorized();

    foreach (['sales', 'viewer'] as $role) {
        Sanctum::actingAs(segapiUser($tenant, $role));

        $this->getJson('/api/v1/segments')->assertOk();
        $this->getJson("/api/v1/segments/{$segment->id}")->assertOk();
        $this->getJson('/api/v1/segments/fields')->assertOk();
        $this->postJson('/api/v1/segments/preview', segapiPayload())->assertOk();
        $this->postJson('/api/v1/segments', segapiPayload(['name' => "New {$role}"]))->assertForbidden();
        $this->putJson("/api/v1/segments/{$segment->id}", segapiPayload(['name' => 'Renamed']))->assertForbidden();
        $this->deleteJson("/api/v1/segments/{$segment->id}")->assertForbidden();
    }

    expect(DB::table('segments')->count())->toBe(1);
    expect(DB::table('segments')->value('name'))->toBe('Keep me');

    foreach (['admin', 'manager'] as $role) {
        Sanctum::actingAs(segapiUser($tenant, $role));
        $this->postJson('/api/v1/segments', segapiPayload(['name' => "By {$role}"]))->assertCreated();
    }
});

it('refuses the Actions to an actor without marketing.manage, whatever called them', function () {
    $tenant = Tenant::factory()->create();
    $segment = segapiSegment($tenant);
    $sales = segapiUser($tenant, 'sales');

    expect(fn () => app(CreateSegmentAction::class)->execute($sales, segapiPayload()))->toThrow(AuthorizationException::class);
    expect(fn () => app(UpdateSegmentAction::class)->execute($sales, $segment->id, segapiPayload()))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeleteSegmentAction::class)->execute($sales, $segment->id))->toThrow(AuthorizationException::class);

    expect(DB::table('segments')->count())->toBe(1);
});

it('serves the field catalog the builder renders', function () {
    Sanctum::actingAs(segapiUser(Tenant::factory()->create(), 'viewer'));

    $response = $this->getJson('/api/v1/segments/fields')->assertOk();

    expect($response->json('max_conditions'))->toBe(20);
    expect($response->json('match_modes'))->toBe(['all', 'any']);
    expect(collect($response->json('data'))->pluck('field')->all())->toBe(['name', 'email', 'job_title', 'phone', 'account', 'created_at', 'lead_score']);
    expect($response->json('data.0'))->toHaveKeys(['field', 'label', 'type', 'operators']);
    expect($response->json('data.0.operators.0'))->toHaveKeys(['operator', 'label', 'value_kind']);
});

it('lists only the actors tenant with the full resource shape and pagination', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = segapiUser($a);
    segapiSegment($a, ['name' => 'Alpha']);
    segapiSegment($a, ['name' => 'Beta']);
    segapiSegment($b, ['name' => 'B-secret']);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/segments')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => SEGAPI_KEYS],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('meta.total', 2);

    expect(array_keys($response->json('data.0')))->toEqualCanonicalizing(SEGAPI_KEYS);
    expect(array_keys($response->json('data.0.conditions.0')))->toBe(['field', 'operator', 'value']);
    expect($response->getContent())->not->toContain('B-secret');
    expect($response->json('data.0'))->not->toHaveKey('tenant_id');
});

it('searches, sorts and paginates', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(segapiUser($tenant));
    segapiSegment($tenant, ['name' => 'Spring buyers', 'description' => 'Bought in spring', 'created_at' => now()->subDays(3)]);
    segapiSegment($tenant, ['name' => 'Autumn leads', 'description' => 'Leaves', 'created_at' => now()->subDays(2)]);
    segapiSegment($tenant, ['name' => 'Winter VIPs', 'description' => null, 'created_at' => now()->subDay()]);

    $names = fn (string $query) => collect($this->getJson("/api/v1/segments?{$query}")->assertOk()->json('data'))->pluck('name')->all();

    expect($names(''))->toBe(['Winter VIPs', 'Autumn leads', 'Spring buyers']);
    expect($names('q=SPRING'))->toBe(['Spring buyers']);
    expect($names('q=leaves'))->toBe(['Autumn leads']); // matches the description too
    expect($names('sort=name&direction=asc'))->toBe(['Autumn leads', 'Spring buyers', 'Winter VIPs']);
    expect($names('q=%25'))->toBe([]); // a literal percent sign, not a wildcard

    $page2 = $this->getJson('/api/v1/segments?per_page=2&page=2&sort=name&direction=asc')->assertOk();
    expect($page2->json('meta.last_page'))->toBe(2);
    expect(collect($page2->json('data'))->pluck('name')->all())->toBe(['Winter VIPs']);

    $this->getJson('/api/v1/segments?sort=tenant_id')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/segments?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

it('shows one segment, and never another tenants', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    Sanctum::actingAs(segapiUser($a));
    $mine = segapiSegment($a, ['name' => 'Mine']);
    $theirs = segapiSegment($b, ['name' => 'Theirs']);

    $this->getJson("/api/v1/segments/{$mine->id}")->assertOk()->assertJsonPath('name', 'Mine');
    $this->getJson("/api/v1/segments/{$theirs->id}")->assertNotFound();
    $this->getJson('/api/v1/segments/999999')->assertNotFound();
});

it('creates a segment whose tenant and creator come from the session, with the conditions normalized', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = segapiUser($a);

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/segments', [
        'name' => '  Warm leads ',
        'description' => 'Recent and reachable',
        'match_mode' => 'any',
        'conditions' => [
            ['field' => 'name', 'operator' => 'contains', 'value' => '  Ann  ', 'extra' => 'dropped', 'tenant_id' => $b->id],
            ['field' => 'email', 'operator' => 'is_empty', 'value' => 'ignored'],
            ['field' => 'created_at', 'operator' => 'in_last_days', 'value' => '7'],
        ],
        'tenant_id' => $b->id,
        'created_by' => 12345,
    ])->assertCreated()
        ->assertJsonPath('name', 'Warm leads')
        ->assertJsonPath('match_mode', 'any');

    expect(array_keys($response->json()))->toEqualCanonicalizing(SEGAPI_KEYS);
    expect($response->json('conditions'))->toBe([
        ['field' => 'name', 'operator' => 'contains', 'value' => 'Ann'],
        ['field' => 'email', 'operator' => 'is_empty', 'value' => null],
        ['field' => 'created_at', 'operator' => 'in_last_days', 'value' => 7],
    ]);

    $row = DB::table('segments')->first();
    expect((int) $row->tenant_id)->toBe($a->id);
    expect((int) $row->created_by)->toBe($admin->id);
    expect(json_decode($row->conditions, true))->toHaveCount(3);
});

it('validates the segment, pointing condition errors at the failing row', function () {
    Sanctum::actingAs(segapiUser(Tenant::factory()->create()));

    $this->postJson('/api/v1/segments', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'match_mode', 'conditions']);
    $this->postJson('/api/v1/segments', segapiPayload(['name' => str_repeat('x', 161)]))->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/segments', segapiPayload(['description' => str_repeat('x', 501)]))->assertStatus(422)->assertJsonValidationErrors('description');
    $this->postJson('/api/v1/segments', segapiPayload(['match_mode' => 'some']))->assertStatus(422)->assertJsonValidationErrors('match_mode');
    $this->postJson('/api/v1/segments', segapiPayload(['match_mode' => ['all']]))->assertStatus(422)->assertJsonValidationErrors('match_mode');

    $this->postJson('/api/v1/segments', segapiPayload(['conditions' => []]))->assertStatus(422)->assertJsonValidationErrors('conditions');
    $this->postJson('/api/v1/segments', segapiPayload(['conditions' => 'nope']))->assertStatus(422)->assertJsonValidationErrors('conditions');
    $this->postJson('/api/v1/segments', segapiPayload(['conditions' => array_fill(0, 21, ['field' => 'name', 'operator' => 'contains', 'value' => 'x'])]))
        ->assertStatus(422)->assertJsonValidationErrors('conditions');

    $response = $this->postJson('/api/v1/segments', segapiPayload(['conditions' => [
        ['field' => 'name', 'operator' => 'contains', 'value' => 'ok'],
        ['field' => 'nope', 'operator' => 'contains', 'value' => 'x'],
        ['field' => 'phone', 'operator' => 'contains', 'value' => 'x'],
        ['field' => 'email', 'operator' => 'equals', 'value' => '  '],
        ['field' => 'created_at', 'operator' => 'in_last_days', 'value' => 0],
    ]]))->assertStatus(422);

    expect(array_keys($response->json('errors')))->toEqualCanonicalizing([
        'conditions.1.field', 'conditions.2.operator', 'conditions.3.value', 'conditions.4.value',
    ]);

    expect(DB::table('segments')->count())->toBe(0);
});

it('keeps segment names unique per tenant, ignoring case, but not across tenants', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = segapiUser($a);
    segapiSegment($a, ['name' => 'Big Spenders']);
    segapiSegment($b, ['name' => 'Other Tenant Thing']);

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/segments', segapiPayload(['name' => 'big spenders']))->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/segments', segapiPayload(['name' => 'Other Tenant Thing']))->assertCreated();

    expect(DB::table('segments')->where('tenant_id', $a->id)->count())->toBe(2);
});

it('updates a segment, ignoring its own name in the uniqueness check and any forged fields', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = segapiUser($a);
    $segment = segapiSegment($a, ['name' => 'Original']);
    segapiSegment($a, ['name' => 'Taken']);

    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/segments/{$segment->id}", segapiPayload([
        'name' => 'original',
        'match_mode' => 'any',
        'conditions' => [['field' => 'job_title', 'operator' => 'starts_with', 'value' => 'VP']],
        'tenant_id' => $b->id,
    ]))->assertOk()
        ->assertJsonPath('name', 'original')
        ->assertJsonPath('match_mode', 'any')
        ->assertJsonPath('conditions.0.field', 'job_title');

    $this->putJson("/api/v1/segments/{$segment->id}", segapiPayload(['name' => 'TAKEN']))->assertStatus(422)->assertJsonValidationErrors('name');
    $this->putJson("/api/v1/segments/{$segment->id}", segapiPayload(['conditions' => [['field' => 'name', 'operator' => 'is_before', 'value' => 'x']]]))
        ->assertStatus(422)->assertJsonValidationErrors('conditions.0.operator');

    $row = DB::table('segments')->where('id', $segment->id)->first();
    expect((int) $row->tenant_id)->toBe($a->id);
    expect($row->name)->toBe('original');
    expect($row->match_mode)->toBe('any');
});

it('deletes a segment', function () {
    $tenant = Tenant::factory()->create();
    $segment = segapiSegment($tenant);

    Sanctum::actingAs(segapiUser($tenant));

    $this->deleteJson("/api/v1/segments/{$segment->id}")->assertNoContent();
    expect(DB::table('segments')->where('id', $segment->id)->exists())->toBeFalse();
    $this->deleteJson("/api/v1/segments/{$segment->id}")->assertNotFound();
});

it('never updates or deletes another tenants segment', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    Sanctum::actingAs(segapiUser($a));
    $theirs = segapiSegment($b, ['name' => 'Theirs']);

    $this->putJson("/api/v1/segments/{$theirs->id}", segapiPayload(['name' => 'Hijacked']))->assertNotFound();
    $this->deleteJson("/api/v1/segments/{$theirs->id}")->assertNotFound();

    expect(DB::table('segments')->where('id', $theirs->id)->value('name'))->toBe('Theirs');
});

it('previews how many of the tenants contacts match, without saving anything', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    Sanctum::actingAs(segapiUser($a));
    segapiContact($a, 'Alice', ['email' => 'alice@acme.test']);
    segapiContact($a, 'Bob', ['email' => 'bob@gmail.com']);
    segapiContact($a, 'Carol');
    segapiContact($a, 'Eve', ['email' => 'eve@acme.test', 'deleted_at' => now()]);
    segapiContact($b, 'Zed', ['email' => 'zed@acme.test']);

    $response = $this->postJson('/api/v1/segments/preview', segapiPayload())->assertOk();

    expect($response->json('data.count'))->toBe(1);
    expect($response->json('data.sample'))->toHaveCount(1);
    expect($response->json('data.sample.0'))->toHaveKeys(['id', 'name', 'email']);
    expect($response->json('data.sample.0.name'))->toBe('Alice');
    expect($response->getContent())->not->toContain('Zed');
    expect(DB::table('segments')->count())->toBe(0);

    $any = $this->postJson('/api/v1/segments/preview', [
        'match_mode' => 'any',
        'conditions' => [
            ['field' => 'email', 'operator' => 'is_empty'],
            ['field' => 'name', 'operator' => 'equals', 'value' => 'bob'],
        ],
    ])->assertOk();
    expect($any->json('data.count'))->toBe(2);
});

it('caps the preview sample at five contacts but counts them all', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(segapiUser($tenant));

    foreach (range(1, 7) as $i) {
        segapiContact($tenant, "Person{$i}", ['email' => "p{$i}@acme.test"]);
    }

    $response = $this->postJson('/api/v1/segments/preview', segapiPayload())->assertOk();

    expect($response->json('data.count'))->toBe(7);
    expect($response->json('data.sample'))->toHaveCount(5);
});

it('validates a preview like a save', function () {
    Sanctum::actingAs(segapiUser(Tenant::factory()->create()));

    $this->postJson('/api/v1/segments/preview', [])->assertStatus(422)->assertJsonValidationErrors(['match_mode', 'conditions']);
    $this->postJson('/api/v1/segments/preview', ['match_mode' => 'all', 'conditions' => []])->assertStatus(422)->assertJsonValidationErrors('conditions');
    $this->postJson('/api/v1/segments/preview', ['match_mode' => 'all', 'conditions' => [['field' => 'nope', 'operator' => 'contains', 'value' => 'x']]])
        ->assertStatus(422)->assertJsonValidationErrors('conditions.0.field');
});
