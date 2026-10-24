<?php

use App\Broadcasting\UserChannel;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\Actions\SetUserActiveStateAction;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DevTenantSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function umgtSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

/** @param array<string, mixed> $attributes */
function umgtUser(Tenant $tenant, string $role = 'admin', array $attributes = []): User
{
    umgtSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function umgtDeactivate(User $user): void
{
    $user->forceFill(['deactivated_at' => now()])->save();
}

it('keeps the role catalog consistent with the seeder', function () {
    $seeded = collect(DevTenantSeeder::PERMISSIONS)->sort()->values()->all();
    $catalog = collect(RoleCatalog::ALL_PERMISSIONS)->sort()->values()->all();

    expect($catalog)->toBe($seeded);
    expect(RoleCatalog::permissionsFor('admin'))->toBe(RoleCatalog::ALL_PERMISSIONS);

    foreach (['manager', 'sales', 'viewer'] as $role) {
        expect(array_diff(RoleCatalog::permissionsFor($role), RoleCatalog::ALL_PERMISSIONS))->toBeEmpty();
        expect(RoleCatalog::permissionsFor($role))->not->toContain('users.manage', 'settings.manage');
    }

    expect(collect(RoleCatalog::permissionsFor('viewer'))->every(fn ($p) => str_ends_with($p, '.view')))->toBeTrue();
});

it('forbids every user-management endpoint without users.manage', function () {
    $tenant = Tenant::factory()->create();
    $manager = umgtUser($tenant, 'manager');
    $other = umgtUser($tenant, 'viewer');
    Sanctum::actingAs($manager);

    $this->getJson('/api/v1/users')->assertForbidden();
    $this->getJson('/api/v1/users/roles')->assertForbidden();
    $this->patchJson("/api/v1/users/{$other->id}/role", ['role' => 'sales'])->assertForbidden();
    $this->postJson("/api/v1/users/{$other->id}/deactivate")->assertForbidden();
    $this->postJson("/api/v1/users/{$other->id}/reactivate")->assertForbidden();
});

it('lists the assignable roles in catalog order', function () {
    $admin = umgtUser(Tenant::factory()->create());
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/users/roles')
        ->assertOk()
        ->assertJsonStructure(['data' => ['*' => ['name', 'label', 'description']]]);

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['admin', 'manager', 'sales', 'viewer']);
});

it('lists only the actors tenant, with the full resource shape and pagination', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = umgtUser($a, 'admin', ['name' => 'Actor']);
    umgtUser($a, 'viewer', ['name' => 'Alice']);
    umgtUser($a, 'sales', ['name' => 'Bob']);
    umgtUser($b, 'admin', ['name' => 'Mallory']);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'name', 'email', 'role', 'role_label', 'active', 'deactivated_at', 'two_factor_enabled', 'is_self', 'created_at']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('meta.total', 3)
        ->assertJsonMissingPath('data.0.password')
        ->assertJsonMissingPath('data.0.tenant_id')
        ->assertJsonMissingPath('data.0.remember_token');

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Actor', 'Alice', 'Bob']);

    $self = collect($response->json('data'))->firstWhere('name', 'Actor');
    expect($self['is_self'])->toBeTrue();
    expect($self['role'])->toBe('admin');
    expect($self['role_label'])->toBe('Administrator');
    expect(collect($response->json('data'))->firstWhere('name', 'Alice')['is_self'])->toBeFalse();
});

it('searches, filters by role and status, and sorts', function () {
    $tenant = Tenant::factory()->create();
    // Fixed emails: a random factory address could contain the search term and make this flaky.
    $admin = umgtUser($tenant, 'admin', ['name' => 'Actor', 'email' => 'actor@example.com']);
    umgtUser($tenant, 'viewer', ['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = umgtUser($tenant, 'sales', ['name' => 'Bob', 'email' => 'bob@example.com']);
    umgtDeactivate($bob);

    Sanctum::actingAs($admin);

    $names = fn (string $query) => collect($this->getJson("/api/v1/users?{$query}")->assertOk()->json('data'))->pluck('name')->all();

    expect($names('q=ALI'))->toBe(['Alice']);
    expect($names('q=bob@example'))->toBe(['Bob']);
    expect($names('status=deactivated'))->toBe(['Bob']);
    expect($names('status=active'))->toBe(['Actor', 'Alice']);
    expect($names('role=sales'))->toBe(['Bob']);
    expect($names('sort=name&direction=desc'))->toBe(['Bob', 'Alice', 'Actor']);
    expect($names('q=%25'))->toBe([]); // a literal percent sign, not a wildcard
});

it('rejects a sort column outside the whitelist', function () {
    $admin = umgtUser(Tenant::factory()->create());
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/users?sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/users?role=superuser')->assertStatus(422)->assertJsonValidationErrors('role');
});

it('changes a role, replacing the previous one', function () {
    $tenant = Tenant::factory()->create();
    $admin = umgtUser($tenant);
    $target = umgtUser($tenant, 'viewer');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$target->id}/role", ['role' => 'sales'])
        ->assertOk()
        ->assertJsonPath('role', 'sales')
        ->assertJsonPath('role_label', 'Sales');

    umgtSwitch($tenant);
    $fresh = User::query()->find($target->id);
    expect($fresh->getRoleNames()->all())->toBe(['sales']);
    expect($fresh->can('contacts.create'))->toBeTrue();
    expect($fresh->can('users.manage'))->toBeFalse();
});

it('rejects an unknown role', function () {
    $tenant = Tenant::factory()->create();
    $admin = umgtUser($tenant);
    $target = umgtUser($tenant, 'viewer');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$target->id}/role", ['role' => 'superuser'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
    $this->patchJson("/api/v1/users/{$target->id}/role", [])->assertStatus(422)->assertJsonValidationErrors('role');
});

it('never reads or changes another tenants user', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = umgtUser($a);
    $mallory = umgtUser($b, 'viewer');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$mallory->id}/role", ['role' => 'admin'])->assertNotFound();
    $this->postJson("/api/v1/users/{$mallory->id}/deactivate")->assertNotFound();
    $this->postJson("/api/v1/users/{$mallory->id}/reactivate")->assertNotFound();

    umgtSwitch($b);
    $fresh = User::query()->find($mallory->id);
    expect($fresh->deactivated_at)->toBeNull();
    expect($fresh->getRoleNames()->all())->toBe(['viewer']);
});

it('refuses to change or deactivate your own account', function () {
    $tenant = Tenant::factory()->create();
    $admin = umgtUser($tenant);
    umgtUser($tenant); // a second administrator, so this is not the last-admin rule
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$admin->id}/role", ['role' => 'viewer'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_modify_self');
    $this->postJson("/api/v1/users/{$admin->id}/deactivate")
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_modify_self');

    umgtSwitch($tenant);
    expect(User::query()->find($admin->id)->deactivated_at)->toBeNull();
    expect(User::query()->find($admin->id)->getRoleNames()->all())->toBe(['admin']);
});

it('protects the last active administrator', function () {
    $tenant = Tenant::factory()->create();
    // The actor can manage users but is not an administrator, so only the last-admin rule can stop them.
    $actor = umgtUser($tenant, 'manager');
    $actor->givePermissionTo('users.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $onlyAdmin = umgtUser($tenant, 'admin');
    Sanctum::actingAs($actor);

    $this->patchJson("/api/v1/users/{$onlyAdmin->id}/role", ['role' => 'viewer'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'last_admin');
    $this->postJson("/api/v1/users/{$onlyAdmin->id}/deactivate")
        ->assertStatus(409)
        ->assertJsonPath('code', 'last_admin');

    // With a second administrator the first can be demoted...
    $second = umgtUser($tenant, 'admin');
    Sanctum::actingAs($actor);
    $this->patchJson("/api/v1/users/{$onlyAdmin->id}/role", ['role' => 'viewer'])->assertOk()->assertJsonPath('role', 'viewer');

    // ...and now the second is the last one again.
    $this->postJson("/api/v1/users/{$second->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'last_admin');
});

it('deactivates, is idempotent, and reactivates', function () {
    $tenant = Tenant::factory()->create();
    $admin = umgtUser($tenant);
    $target = umgtUser($tenant, 'sales');
    $originalToken = $target->remember_token;
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/users/{$target->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('active', false);

    umgtSwitch($tenant);
    $fresh = User::query()->find($target->id);
    expect($fresh->deactivated_at)->not->toBeNull();
    expect($fresh->remember_token)->not->toBe($originalToken);
    $stamp = $fresh->deactivated_at->toIso8601String();

    $this->postJson("/api/v1/users/{$target->id}/deactivate")->assertOk()->assertJsonPath('active', false);
    expect(User::query()->find($target->id)->deactivated_at->toIso8601String())->toBe($stamp);

    $this->postJson("/api/v1/users/{$target->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('active', true)
        ->assertJsonPath('deactivated_at', null);
});

it('blocks sign-in of a deactivated account with the same generic failure as a wrong password', function () {
    $tenant = Tenant::factory()->create();
    $user = umgtUser($tenant, 'sales');
    $headers = ['Referer' => 'http://localhost:5173'];

    $wrong = $this->withHeaders($headers)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertStatus(422);

    $this->withHeaders($headers)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $this->withHeaders($headers)->postJson('/api/v1/logout')->assertOk();

    umgtDeactivate($user);

    $blocked = $this->withHeaders($headers)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(422);
    expect($blocked->json('errors.email'))->toBe($wrong->json('errors.email'));

    $user->forceFill(['deactivated_at' => null])->save();
    $this->withHeaders($headers)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
});

it('cuts off an open session on its next request', function () {
    $tenant = Tenant::factory()->create();
    $user = umgtUser($tenant, 'sales');
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/me')->assertOk();

    umgtDeactivate($user);

    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('denies the private user channel to a deactivated user', function () {
    $tenant = Tenant::factory()->create();
    $user = umgtUser($tenant, 'sales');

    expect((new UserChannel())->join($user, $tenant->id, $user->id))->toBeTrue();

    umgtDeactivate($user);

    expect((new UserChannel())->join($user, $tenant->id, $user->id))->toBeFalse();
});

it('drops database session rows when an account is deactivated', function () {
    $tenant = Tenant::factory()->create();
    $admin = umgtUser($tenant);
    $target = umgtUser($tenant, 'sales');
    $bystander = umgtUser($tenant, 'viewer');

    config(['session.driver' => 'database']);
    foreach ([[$target, 'sess-target'], [$bystander, 'sess-other']] as [$user, $id]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    }

    umgtSwitch($tenant);
    app(SetUserActiveStateAction::class)->execute($admin, $target->id, false);

    expect(DB::table('sessions')->where('id', 'sess-target')->exists())->toBeFalse();
    expect(DB::table('sessions')->where('id', 'sess-other')->exists())->toBeTrue();
});
