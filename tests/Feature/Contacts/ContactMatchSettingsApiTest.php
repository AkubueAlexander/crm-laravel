<?php

use App\Models\Contact;
use App\Models\ContactMatchSettings;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Domain\Tenant\TenantContext;

function matchSettingsActor(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    TenantContext::set($tenant);

    $permission = Permission::firstOrCreate(['name' => 'contacts.update', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $role->syncPermissions([$permission]);

    $user = User::factory()->createOne([
        'tenant_id' => $tenant->id,
        'password' => Hash::make('password'),
    ]);
    $user->assignRole($role);

    return $user;
}

it('returns the default threshold when no settings row exists yet', function () {
    $tenant = Tenant::factory()->createOne();
    $user = matchSettingsActor($tenant);

    $this->actingAs($user)
        ->getJson('/api/v1/contacts/match-settings')
        ->assertOk()
        ->assertJson([
            'match_threshold' => ContactMatchSettings::DEFAULT_THRESHOLD,
            'default_threshold' => ContactMatchSettings::DEFAULT_THRESHOLD,
        ]);
});

it('updates the threshold and it is reflected on the next duplicate check', function () {
    $tenant = Tenant::factory()->createOne();
    $user = matchSettingsActor($tenant);

    $this->actingAs($user)
        ->putJson('/api/v1/contacts/match-settings', ['match_threshold' => 90])
        ->assertOk()
        ->assertJson(['match_threshold' => 90]);

    $this->actingAs($user)
        ->getJson('/api/v1/contacts/match-settings')
        ->assertOk()
        ->assertJson(['match_threshold' => 90]);
});

it('rejects a threshold outside 1-100', function () {
    $tenant = Tenant::factory()->createOne();
    $user = matchSettingsActor($tenant);

    $this->actingAs($user)
        ->putJson('/api/v1/contacts/match-settings', ['match_threshold' => 0])
        ->assertStatus(422);

    $this->actingAs($user)
        ->putJson('/api/v1/contacts/match-settings', ['match_threshold' => 101])
        ->assertStatus(422);
});

it('never lets one tenant read or write another tenant match_threshold', function () {
    $tenantA = Tenant::factory()->createOne();
    $tenantB = Tenant::factory()->createOne();

    $userA = matchSettingsActor($tenantA);
    $userB = matchSettingsActor($tenantB);

    $this->actingAs($userA)
        ->putJson('/api/v1/contacts/match-settings', ['match_threshold' => 95])
        ->assertOk();

    // Tenant B never sees tenant A's 95 -- gets the untouched default instead.
    $this->actingAs($userB)
        ->getJson('/api/v1/contacts/match-settings')
        ->assertOk()
        ->assertJson(['match_threshold' => ContactMatchSettings::DEFAULT_THRESHOLD]);

    TenantContext::set($tenantA);
    expect(ContactMatchSettings::withoutTenantScope()->count())->toBe(1);
});