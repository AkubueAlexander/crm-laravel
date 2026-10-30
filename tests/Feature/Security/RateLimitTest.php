<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

// 14.4: the limiter must not leak between tests (or runs), so every test pins it to the array store.
function rlimIsolate(): void
{
    config(['cache.limiter' => 'array', 'logging.default' => 'null']);
}

function rlimActor(): User
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('throttles login attempts with a structured 429 and Retry-After', function () {
    rlimIsolate();

    $limited = null;
    for ($i = 1; $i <= 12; $i++) {
        $response = $this->postJson('/api/v1/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);
        if ($response->status() === 429) {
            $limited = $response;
            break;
        }
    }

    expect($limited)->not->toBeNull();
    $limited->assertJson(['code' => 'http_429', 'errors' => []])->assertHeader('Retry-After');
});

it('throttles public lead form submissions at 10 per minute', function () {
    rlimIsolate();
    $uuid = '00000000-0000-4000-8000-000000000000';

    for ($i = 1; $i <= 10; $i++) {
        expect($this->postJson("/api/public/forms/{$uuid}", [])->status())->not->toBe(429);
    }

    $this->postJson("/api/public/forms/{$uuid}", [])
        ->assertStatus(429)
        ->assertJson(['code' => 'http_429']);
});

it('throttles invitation acceptance at 10 per minute', function () {
    rlimIsolate();
    $token = str_repeat('a', 48);

    for ($i = 1; $i <= 10; $i++) {
        expect($this->postJson("/api/v1/invitations/{$token}/accept", [])->status())->not->toBe(429);
    }

    $this->postJson("/api/v1/invitations/{$token}/accept", [])
        ->assertStatus(429)
        ->assertJson(['code' => 'http_429']);
});

it('throttles client error reports at 20 per minute per user', function () {
    rlimIsolate();
    Sanctum::actingAs(rlimActor());

    for ($i = 1; $i <= 20; $i++) {
        $this->postJson('/api/v1/client-errors', ['message' => 'boom'])->assertNoContent();
    }

    $this->postJson('/api/v1/client-errors', ['message' => 'boom'])
        ->assertStatus(429)
        ->assertJson(['code' => 'http_429']);
});
