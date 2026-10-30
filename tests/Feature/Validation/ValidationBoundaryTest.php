<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function vlayActor(string $role): User
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

// 14.2: the backend is the validation boundary; the SPA maps errors.<field> onto form fields, so the keys matter.
it('rejects an invalid quote settings payload with a field-keyed 422', function () {
    config(['cache.limiter' => 'array', 'logging.default' => 'null']);
    Sanctum::actingAs(vlayActor('admin'));

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => 'not-a-number'])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonStructure(['message', 'errors', 'code'])
        ->assertJsonValidationErrors(['approval_threshold']);
});

it('forbids quote settings changes for a viewer with the structured 403 shape', function () {
    config(['cache.limiter' => 'array', 'logging.default' => 'null']);
    Sanctum::actingAs(vlayActor('viewer'));

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => '100'])
        ->assertStatus(403)
        ->assertJson(['code' => 'http_403', 'errors' => []]);
});
