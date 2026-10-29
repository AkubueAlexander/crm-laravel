<?php

use App\Domain\Hr\Actions\SaveHrUnitAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function hrunitUser(Tenant $tenant, string $role): User
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function hrunitInsert(Tenant $tenant, string $kind, string $name): int
{
    return (int) DB::table('hr_units')->insertGetId([
        'tenant_id' => $tenant->id,
        'kind' => $kind,
        'name' => $name,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('creates teams and territories and keeps the kinds separate', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(hrunitUser($tenant, 'admin'));

    $this->postJson('/api/v1/hr/teams', ['name' => 'Enterprise', 'description' => 'Large accounts'])
        ->assertCreated()
        ->assertJsonPath('kind', 'team')
        ->assertJsonPath('name', 'Enterprise')
        ->assertJsonPath('is_active', true);

    // Same name, other kind: allowed.
    $this->postJson('/api/v1/hr/territories', ['name' => 'Enterprise'])->assertCreated()->assertJsonPath('kind', 'territory');

    // Same name and kind, different case: refused.
    $this->postJson('/api/v1/hr/teams', ['name' => 'enterprise'])->assertStatus(422)->assertJsonValidationErrors(['name']);

    expect($this->getJson('/api/v1/hr/teams')->assertOk()->json('meta.total'))->toBe(1);
    expect($this->getJson('/api/v1/hr/territories')->assertOk()->json('meta.total'))->toBe(1);
});

it('lists only the tenant units, sorted by name, and never leaks another tenant', function () {
    $other = Tenant::factory()->create();
    hrunitUser($other, 'admin');
    $otherId = hrunitInsert($other, 'team', 'Zeta');

    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(hrunitUser($tenant, 'admin'));

    $this->postJson('/api/v1/hr/teams', ['name' => 'Beta'])->assertCreated();
    $beta = $this->getJson('/api/v1/hr/teams')->json('data.0.id');
    $this->postJson('/api/v1/hr/teams', ['name' => 'Alpha'])->assertCreated();
    $this->putJson("/api/v1/hr/teams/{$beta}", ['name' => 'Beta', 'is_active' => false])->assertOk();

    expect($this->getJson('/api/v1/hr/teams')->assertOk()->json('data.*.name'))->toBe(['Alpha', 'Beta']);
    expect($this->getJson('/api/v1/hr/teams?active=0')->assertOk()->json('data.*.name'))->toBe(['Beta']);
    expect($this->getJson('/api/v1/hr/teams?q=alp')->assertOk()->json('data.*.name'))->toBe(['Alpha']);

    // The same name as another tenant's unit is fine; its id is a 404.
    $this->postJson('/api/v1/hr/teams', ['name' => 'zeta'])->assertCreated();
    $this->getJson("/api/v1/hr/teams/{$otherId}")->assertNotFound();
    $this->putJson("/api/v1/hr/teams/{$otherId}", ['name' => 'Hacked'])->assertNotFound();
    expect(DB::table('hr_units')->where('id', $otherId)->value('name'))->toBe('Zeta');
});

it('lets viewers and sales read but only managers and admins write', function () {
    $tenant = Tenant::factory()->create();
    $viewer = hrunitUser($tenant, 'viewer');
    $sales = hrunitUser($tenant, 'sales');
    $manager = hrunitUser($tenant, 'manager');

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/hr/teams')->assertOk();
    $this->postJson('/api/v1/hr/teams', ['name' => 'Nope'])->assertForbidden();

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/hr/territories')->assertOk();
    $this->postJson('/api/v1/hr/territories', ['name' => 'Nope'])->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson('/api/v1/hr/teams', ['name' => 'Allowed'])->assertCreated();
});

it('edits and deactivates a unit, and a unit is only reachable under its own kind', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(hrunitUser($tenant, 'admin'));

    $id = $this->postJson('/api/v1/hr/territories', ['name' => 'North'])->assertCreated()->json('id');

    $this->putJson("/api/v1/hr/territories/{$id}", ['name' => 'North East', 'description' => 'NE region', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('name', 'North East')
        ->assertJsonPath('is_active', false);

    // Omitting is_active keeps the current value.
    $this->putJson("/api/v1/hr/territories/{$id}", ['name' => 'North East'])->assertOk()->assertJsonPath('is_active', false);

    $this->getJson("/api/v1/hr/teams/{$id}")->assertNotFound();
    $this->putJson("/api/v1/hr/teams/{$id}", ['name' => 'Wrong'])->assertNotFound();
});

it('validates the name and refuses an unknown kind in the Action', function () {
    $tenant = Tenant::factory()->create();
    $admin = hrunitUser($tenant, 'admin');
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/hr/teams', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/hr/teams', ['name' => str_repeat('x', 121)])->assertStatus(422)->assertJsonValidationErrors(['name']);

    expect(fn () => app(SaveHrUnitAction::class)->execute($admin, 'department', null, ['name' => 'X']))->toThrow(ValidationException::class);
});
