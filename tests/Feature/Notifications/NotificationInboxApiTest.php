<?php

use App\Domain\Tenant\TenantContext;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function inbxTenant(string $slug)
{
    $class = class_exists(\App\Models\Tenant::class)
        ? \App\Models\Tenant::class
        : \App\Domain\Tenant\Tenant::class;

    return $class::create(['name' => $slug, 'slug' => $slug]);
}

function inbxSwitch($tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function inbxUser($tenant): User
{
    inbxSwitch($tenant);

    return User::factory()->create(['tenant_id' => $tenant->id]);
}

function inbxMake(User $user, int|string $tenantId, bool $read = false, string $subject = 'Call'): DatabaseNotification
{
    return DatabaseNotification::forceCreate([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['tenant_id' => (int) $tenantId, 'kind' => 'activity_overdue', 'subject' => $subject],
        'read_at' => $read ? now() : null,
    ]);
}

function inbxGet(User $user, string $uri = '/api/v1/notifications')
{
    Sanctum::actingAs($user);

    return test()->getJson($uri);
}

it('lists own notifications for the current tenant with an unread count', function () {
    $a = inbxTenant('inbx-a');
    $user = inbxUser($a);
    inbxMake($user, $a->id, false, 'One');
    inbxMake($user, $a->id, true, 'Two');

    inbxGet($user)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.kind', 'activity_overdue')
        ->assertJsonMissingPath('data.0.data.tenant_id');
});

it('never lists another users notifications in the same tenant', function () {
    $a = inbxTenant('inbx-a');
    $me = inbxUser($a);
    $other = inbxUser($a);
    inbxMake($other, $a->id);

    inbxGet($me)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
});

it('hides a notification stamped with a different tenant even for the same user', function () {
    $a = inbxTenant('inbx-a');
    $b = inbxTenant('inbx-b');
    $user = inbxUser($a);
    inbxMake($user, $b->id, false, 'Foreign');
    inbxMake($user, $a->id, false, 'Mine');

    inbxGet($user)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.data.subject', 'Mine');
});

it('marks one notification read', function () {
    $a = inbxTenant('inbx-a');
    $user = inbxUser($a);
    $n = inbxMake($user, $a->id);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/notifications/{$n->id}/read")
        ->assertOk()
        ->assertJsonPath('id', $n->id);

    expect($n->fresh()->read_at)->not->toBeNull();
});

it('returns 404 when marking another users notification read', function () {
    $a = inbxTenant('inbx-a');
    $me = inbxUser($a);
    $other = inbxUser($a);
    $n = inbxMake($other, $a->id);

    Sanctum::actingAs($me);
    $this->postJson("/api/v1/notifications/{$n->id}/read")->assertNotFound();

    expect($n->fresh()->read_at)->toBeNull();
});

it('returns 404 for a malformed notification id', function () {
    $a = inbxTenant('inbx-a');
    $user = inbxUser($a);

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/notifications/not-a-uuid/read')->assertNotFound();
});

it('marks all of the callers current-tenant notifications read and nothing else', function () {
    $a = inbxTenant('inbx-a');
    $b = inbxTenant('inbx-b');
    $me = inbxUser($a);
    $other = inbxUser($a);
    inbxMake($me, $a->id);
    inbxMake($me, $a->id);
    inbxMake($me, $b->id);
    inbxMake($other, $a->id);

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('marked', 2)
        ->assertJsonPath('unread_count', 0);

    // The foreign-tenant row and the other user's row are untouched.
    expect(DatabaseNotification::whereNull('read_at')->count())->toBe(2);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
});
