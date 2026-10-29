<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function hrempUser(Tenant $tenant, string $role): User
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function hrempUnit(Tenant $tenant, string $kind, string $name, bool $active = true): int
{
    return (int) DB::table('hr_units')->insertGetId([
        'tenant_id' => $tenant->id,
        'kind' => $kind,
        'name' => $name,
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param array<string, mixed> $over */
function hrempBody(array $over = []): array
{
    return array_merge([
        'name' => 'Ada Obi',
        'email' => 'ada@example.test',
        'phone' => '555-0100',
        'job_title' => 'Account Executive',
        'hire_date' => '2024-03-01',
    ], $over);
}

it('creates an employee linked to a user, a team and a territory', function () {
    $tenant = Tenant::factory()->create();
    $admin = hrempUser($tenant, 'admin');
    $rep = User::factory()->create(['tenant_id' => $tenant->id]);
    $team = hrempUnit($tenant, 'team', 'Enterprise');
    $territory = hrempUnit($tenant, 'territory', 'North');
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/hr/employees', hrempBody(['user_id' => $rep->id, 'team_id' => $team, 'territory_id' => $territory]))
        ->assertCreated()
        ->assertJsonPath('name', 'Ada Obi')
        ->assertJsonPath('hire_date', '2024-03-01')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('user_id', $rep->id)
        ->assertJsonPath('user_name', $rep->name)
        ->assertJsonPath('team.name', 'Enterprise')
        ->assertJsonPath('territory.name', 'North');

    expect($this->getJson('/api/v1/hr/employees')->assertOk()->json('meta.total'))->toBe(1);
});

it('refuses ids from another tenant, the wrong kind, duplicates and a bad name', function () {
    $other = Tenant::factory()->create();
    hrempUser($other, 'admin');
    $foreignTeam = hrempUnit($other, 'team', 'Foreign');
    $foreignUser = User::factory()->create(['tenant_id' => $other->id]);

    $tenant = Tenant::factory()->create();
    $admin = hrempUser($tenant, 'admin');
    $rep = User::factory()->create(['tenant_id' => $tenant->id]);
    $team = hrempUnit($tenant, 'team', 'Enterprise');
    $territory = hrempUnit($tenant, 'territory', 'North');
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/hr/employees', hrempBody(['team_id' => $foreignTeam]))->assertStatus(422)->assertJsonValidationErrors(['team_id']);
    $this->postJson('/api/v1/hr/employees', hrempBody(['user_id' => $foreignUser->id]))->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    // A territory id in the team slot (and vice versa) is the wrong kind.
    $this->postJson('/api/v1/hr/employees', hrempBody(['team_id' => $territory]))->assertStatus(422)->assertJsonValidationErrors(['team_id']);
    $this->postJson('/api/v1/hr/employees', hrempBody(['territory_id' => $team]))->assertStatus(422)->assertJsonValidationErrors(['territory_id']);
    $this->postJson('/api/v1/hr/employees', hrempBody(['name' => '']))->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/hr/employees', hrempBody(['hire_date' => '01/03/2024']))->assertStatus(422)->assertJsonValidationErrors(['hire_date']);

    $this->postJson('/api/v1/hr/employees', hrempBody(['user_id' => $rep->id]))->assertCreated();
    // The same user cannot be linked twice; the same email (any case) cannot be reused.
    $this->postJson('/api/v1/hr/employees', hrempBody(['name' => 'Second', 'email' => 'second@example.test', 'user_id' => $rep->id]))
        ->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    $this->postJson('/api/v1/hr/employees', hrempBody(['name' => 'Third', 'email' => 'ADA@example.test']))
        ->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('keeps an inactive unit an employee already holds but refuses it for new assignments', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(hrempUser($tenant, 'admin'));
    $team = hrempUnit($tenant, 'team', 'Old team');

    $id = $this->postJson('/api/v1/hr/employees', hrempBody(['team_id' => $team]))->assertCreated()->json('id');
    DB::table('hr_units')->where('id', $team)->update(['is_active' => false]);

    // Unchanged: fine.
    $this->putJson("/api/v1/hr/employees/{$id}", hrempBody(['team_id' => $team, 'job_title' => 'Senior AE']))->assertOk()->assertJsonPath('job_title', 'Senior AE');
    // A different employee cannot be put on it.
    $this->postJson('/api/v1/hr/employees', hrempBody(['name' => 'Bola', 'email' => 'bola@example.test', 'team_id' => $team]))
        ->assertStatus(422)->assertJsonValidationErrors(['team_id']);
});

it('lists only this tenant, filters, and gives other tenants ids a 404', function () {
    $other = Tenant::factory()->create();
    hrempUser($other, 'admin');
    $foreignId = (int) DB::table('employees')->insertGetId([
        'tenant_id' => $other->id, 'name' => 'Zed Foreign', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(hrempUser($tenant, 'admin'));
    $teamA = hrempUnit($tenant, 'team', 'A team');
    $teamB = hrempUnit($tenant, 'team', 'B team');

    $this->postJson('/api/v1/hr/employees', hrempBody(['name' => 'Beta', 'email' => 'beta@example.test', 'team_id' => $teamA]))->assertCreated();
    $gammaId = $this->postJson('/api/v1/hr/employees', hrempBody(['name' => 'Gamma', 'email' => 'gamma@example.test', 'team_id' => $teamB]))->assertCreated()->json('id');
    $this->postJson('/api/v1/hr/employees', hrempBody(['name' => 'Alpha', 'email' => 'alpha@example.test', 'team_id' => $teamA]))->assertCreated();
    $this->putJson("/api/v1/hr/employees/{$gammaId}", hrempBody(['name' => 'Gamma', 'email' => 'gamma@example.test', 'team_id' => $teamB, 'is_active' => false]))->assertOk();

    expect($this->getJson('/api/v1/hr/employees')->assertOk()->json('data.*.name'))->toBe(['Alpha', 'Beta', 'Gamma']);
    expect($this->getJson("/api/v1/hr/employees?team_id={$teamA}")->assertOk()->json('data.*.name'))->toBe(['Alpha', 'Beta']);
    expect($this->getJson('/api/v1/hr/employees?active=0')->assertOk()->json('data.*.name'))->toBe(['Gamma']);
    expect($this->getJson('/api/v1/hr/employees?q=alp')->assertOk()->json('data.*.name'))->toBe(['Alpha']);

    $this->getJson("/api/v1/hr/employees/{$foreignId}")->assertNotFound();
    $this->putJson("/api/v1/hr/employees/{$foreignId}", hrempBody(['name' => 'Hacked', 'email' => 'x@example.test']))->assertNotFound();
    expect(DB::table('employees')->where('id', $foreignId)->value('name'))->toBe('Zed Foreign');
});

it('lets viewers and sales read but only managers and admins write', function () {
    $tenant = Tenant::factory()->create();
    $viewer = hrempUser($tenant, 'viewer');
    $sales = hrempUser($tenant, 'sales');
    $manager = hrempUser($tenant, 'manager');

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/hr/employees')->assertOk();
    $this->postJson('/api/v1/hr/employees', hrempBody())->assertForbidden();

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/hr/employees')->assertOk();
    $this->postJson('/api/v1/hr/employees', hrempBody())->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson('/api/v1/hr/employees', hrempBody())->assertCreated();
});

it('replaces the profile on edit, clears omitted fields and keeps is_active when omitted', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(hrempUser($tenant, 'admin'));
    $team = hrempUnit($tenant, 'team', 'Enterprise');

    $id = $this->postJson('/api/v1/hr/employees', hrempBody(['team_id' => $team]))->assertCreated()->json('id');
    $this->putJson("/api/v1/hr/employees/{$id}", ['name' => 'Ada Obi', 'is_active' => false])->assertOk();

    $this->putJson("/api/v1/hr/employees/{$id}", ['name' => 'Ada Okafor'])
        ->assertOk()
        ->assertJsonPath('name', 'Ada Okafor')
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('team', null)
        ->assertJsonPath('email', null);
});
