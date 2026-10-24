<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function audvUser(Tenant $tenant, string $role = 'admin', array $attributes = []): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @return list<object> audit_events rows oldest first, properties decoded */
function audvEvents(): array
{
    return DB::table('audit_events')->orderBy('id')->get()->map(function ($row) {
        $row->properties = $row->properties === null ? null : json_decode($row->properties, true);

        return $row;
    })->all();
}

/** @return list<string> */
function audvNames(): array
{
    return array_map(fn ($row) => $row->event, audvEvents());
}

it('records a role change with the previous and new role', function () {
    $tenant = Tenant::factory()->create();
    $admin = audvUser($tenant, 'admin');
    $target = audvUser($tenant, 'viewer', ['name' => 'Bob']);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$target->id}/role", ['role' => 'sales'])->assertOk();

    $rows = audvEvents();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->event)->toBe('user.role_changed');
    expect((int) $rows[0]->tenant_id)->toBe($tenant->id);
    expect((int) $rows[0]->user_id)->toBe($admin->id);
    expect($rows[0]->subject_type)->toBe('user');
    expect((int) $rows[0]->subject_id)->toBe($target->id);
    expect($rows[0]->subject_label)->toBe('Bob');
    expect($rows[0]->properties)->toEqualCanonicalizing(['from' => 'viewer', 'to' => 'sales']);

    // Choosing the role they already have changes nothing, so it is not an event.
    $this->patchJson("/api/v1/users/{$target->id}/role", ['role' => 'sales'])->assertOk();
    expect(audvNames())->toBe(['user.role_changed']);
});

it('records nothing when a role change is refused', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = audvUser($a, 'admin');
    audvUser($a, 'admin'); // a second administrator, so self-change is refused by the self rule
    $mallory = audvUser($b, 'viewer');
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$admin->id}/role", ['role' => 'viewer'])->assertStatus(422);
    $this->patchJson("/api/v1/users/{$mallory->id}/role", ['role' => 'admin'])->assertNotFound();
    $this->patchJson("/api/v1/users/{$admin->id}/role", ['role' => 'superuser'])->assertStatus(422);

    expect(audvEvents())->toBe([]);
});

it('records nothing when the last administrator is protected', function () {
    $tenant = Tenant::factory()->create();
    $actor = audvUser($tenant, 'manager');
    $actor->givePermissionTo('users.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $onlyAdmin = audvUser($tenant, 'admin');
    Sanctum::actingAs($actor);

    $this->patchJson("/api/v1/users/{$onlyAdmin->id}/role", ['role' => 'viewer'])->assertStatus(409);
    $this->postJson("/api/v1/users/{$onlyAdmin->id}/deactivate")->assertStatus(409);

    expect(audvEvents())->toBe([]);
});

it('records deactivation and reactivation once each, never for a no-op', function () {
    $tenant = Tenant::factory()->create();
    $admin = audvUser($tenant, 'admin');
    $target = audvUser($tenant, 'sales', ['name' => 'Cleo']);
    Sanctum::actingAs($admin);

    // Reactivating an account that is already active is a no-op.
    $this->postJson("/api/v1/users/{$target->id}/reactivate")->assertOk();
    expect(audvEvents())->toBe([]);

    $this->postJson("/api/v1/users/{$target->id}/deactivate")->assertOk();
    $this->postJson("/api/v1/users/{$target->id}/deactivate")->assertOk(); // idempotent
    expect(audvNames())->toBe(['user.deactivated']);

    $this->postJson("/api/v1/users/{$target->id}/reactivate")->assertOk();
    expect(audvNames())->toBe(['user.deactivated', 'user.reactivated']);

    $rows = audvEvents();
    expect((int) $rows[0]->user_id)->toBe($admin->id);
    expect((int) $rows[0]->subject_id)->toBe($target->id);
    expect($rows[0]->subject_label)->toBe('Cleo');

    // Refused self-deactivation records nothing more.
    $this->postJson("/api/v1/users/{$admin->id}/deactivate")->assertStatus(422);
    expect(audvNames())->toBe(['user.deactivated', 'user.reactivated']);
});

it('records invitation create, re-issue, resend and revoke without any token', function () {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    $admin = audvUser($tenant, 'admin');
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/users/invitations', ['email' => 'New@Example.com', 'role' => 'sales'])->assertCreated();
    $this->postJson('/api/v1/users/invitations', ['email' => 'new@example.com', 'role' => 'manager'])->assertOk();
    $id = UserInvitation::query()->firstOrFail()->id;
    $this->postJson("/api/v1/users/invitations/{$id}/resend")->assertOk();
    $this->deleteJson("/api/v1/users/invitations/{$id}")->assertNoContent();

    expect(audvNames())->toBe(['invitation.created', 'invitation.reissued', 'invitation.resent', 'invitation.revoked']);

    $rows = audvEvents();
    foreach ($rows as $row) {
        expect((int) $row->user_id)->toBe($admin->id);
        expect($row->subject_type)->toBe('invitation');
        expect((int) $row->subject_id)->toBe($id);
        expect($row->subject_label)->toBe('new@example.com');
        expect(array_keys($row->properties))->toBe(['role']);
    }
    expect($rows[0]->properties['role'])->toBe('sales');
    expect($rows[1]->properties['role'])->toBe('manager');
    expect($rows[3]->properties['role'])->toBe('manager');
});

it('records nothing for a refused or cross-tenant invitation action', function () {
    Notification::fake();
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = audvUser($a, 'admin');
    audvUser($a, 'viewer', ['email' => 'taken@example.com']);

    $foreign = new UserInvitation();
    $foreign->forceFill([
        'tenant_id' => $b->id,
        'email' => 'foreign@example.com',
        'role' => 'viewer',
        'token_hash' => hash('sha256', Str::random(40)),
        'expires_at' => now()->addDays(7),
    ])->save();

    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/users/invitations', ['email' => 'taken@example.com', 'role' => 'sales'])->assertStatus(422);
    $this->postJson("/api/v1/users/invitations/{$foreign->id}/resend")->assertNotFound();
    $this->deleteJson("/api/v1/users/invitations/{$foreign->id}")->assertNotFound();

    expect(audvEvents())->toBe([]);
});

it('records an accepted invitation with the new user as actor, and nothing for a dead link', function () {
    $tenant = Tenant::factory()->create();
    $token = Str::random(48);
    $invitation = new UserInvitation();
    $invitation->forceFill([
        'tenant_id' => $tenant->id,
        'email' => 'invitee@example.com',
        'role' => 'sales',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ])->save();

    $headers = ['Referer' => 'http://localhost:5173'];
    $body = ['name' => 'New Person', 'password' => 'correct-horse-battery-1', 'password_confirmation' => 'correct-horse-battery-1'];

    $this->withHeaders($headers)->postJson('/api/v1/invitations/'.Str::random(48).'/accept', $body)->assertStatus(410);
    expect(audvEvents())->toBe([]);

    $this->withHeaders($headers)->postJson("/api/v1/invitations/{$token}/accept", $body)->assertOk();

    $user = User::query()->where('email', 'invitee@example.com')->firstOrFail();
    $rows = audvEvents();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->event)->toBe('invitation.accepted');
    expect((int) $rows[0]->tenant_id)->toBe($tenant->id);
    expect((int) $rows[0]->user_id)->toBe($user->id);
    expect((int) $rows[0]->subject_id)->toBe($user->id);
    expect($rows[0]->subject_label)->toBe('New Person');
    expect($rows[0]->properties)->toBe(['role' => 'sales']);

    // The used link cannot record a second acceptance.
    $this->withHeaders($headers)->postJson("/api/v1/invitations/{$token}/accept", $body)->assertStatus(410);
    expect(audvNames())->toBe(['invitation.accepted']);
});

it('shows the recorded events in the audit feed', function () {
    $tenant = Tenant::factory()->create();
    $admin = audvUser($tenant, 'admin', ['name' => 'Ada']);
    $target = audvUser($tenant, 'viewer', ['name' => 'Bob']);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$target->id}/role", ['role' => 'sales'])->assertOk();

    $this->getJson('/api/v1/audit-log')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'user.role_changed')
        ->assertJsonPath('data.0.actor.name', 'Ada')
        ->assertJsonPath('data.0.subject.label', 'Bob')
        ->assertJsonPath('data.0.details.from', 'viewer')
        ->assertJsonPath('data.0.details.to', 'sales');
});
