<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Logging\TenantContextTap;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Spatie\Permission\PermissionRegistrar;

function obsvActor(): User
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

it('returns a structured 401 for unauthenticated requests', function () {
    $this->postJson('/api/v1/client-errors', ['message' => 'x'])
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated', 'errors' => []])
        ->assertJsonStructure(['message', 'errors', 'code']);
});

it('returns a structured 404 for unknown routes', function () {
    $this->getJson('/api/v1/obsv-does-not-exist')
        ->assertStatus(404)
        ->assertJson(['code' => 'http_404', 'errors' => []])
        ->assertJsonStructure(['message', 'errors', 'code']);
});

it('returns a generic structured 500 without leaking the exception message', function () {
    config(['app.debug' => false, 'logging.default' => 'null']);

    Route::middleware('api')->get('/api/obsv-boom', function () {
        throw new RuntimeException('boom-secret-detail');
    });

    $response = $this->getJson('/api/obsv-boom');

    $response->assertStatus(500)
        ->assertJson(['code' => 'server_error', 'errors' => [], 'message' => 'Server error.']);
    expect($response->getContent())->not->toContain('boom-secret-detail');
});

it('assigns a request id and echoes a well-formed incoming one', function () {
    $generated = $this->getJson('/api/v1/obsv-does-not-exist');
    $generated->assertHeader('X-Request-Id');
    expect($generated->headers->get('X-Request-Id'))->toHaveLength(36);

    $this->getJson('/api/v1/obsv-does-not-exist', ['X-Request-Id' => 'req-12345678'])
        ->assertHeader('X-Request-Id', 'req-12345678');

    $replaced = $this->getJson('/api/v1/obsv-does-not-exist', ['X-Request-Id' => 'bad id!']);
    expect($replaced->headers->get('X-Request-Id'))->not->toBe('bad id!');
});

it('enriches log records with tenant, user and request context', function () {
    $user = obsvActor();
    $tenant = Tenant::query()->findOrFail($user->tenant_id);

    $handler = new TestHandler();
    $logger = new Logger('obsv', [$handler]);
    (new TenantContextTap())($logger);

    app('request')->attributes->set('request_id', 'req-12345678');
    app('request')->setUserResolver(fn () => $user);

    $logger->warning('with context');
    $extra = $handler->getRecords()[0]->extra;

    expect($extra['tenant_id'])->toEqual($tenant->id)
        ->and($extra['user_id'])->toEqual($user->id)
        ->and($extra['request_id'])->toBe('req-12345678');

    TenantContext::clear();
    $logger->warning('without tenant');
    expect($handler->getRecords()[1]->extra)->not->toHaveKey('tenant_id');
});

it('accepts a client error report from an authenticated user', function () {
    config(['logging.default' => 'null']);
    Sanctum::actingAs(obsvActor());

    $this->postJson('/api/v1/client-errors', [
        'message' => 'Cannot read properties of undefined',
        'stack' => 'at Board (Board.tsx:10)',
        'url' => '/deals',
        'source' => 'render',
    ])->assertNoContent();
});

it('validates the client error report shape', function () {
    Sanctum::actingAs(obsvActor());

    $this->postJson('/api/v1/client-errors', ['source' => 'render'])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors(['message']);

    $this->postJson('/api/v1/client-errors', ['message' => 'x', 'source' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source']);
});
