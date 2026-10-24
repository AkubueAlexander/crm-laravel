<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function invtUser(Tenant $tenant, string $role = 'admin', array $attributes = []): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $attributes */
function invtInvite(Tenant $tenant, array $attributes = []): UserInvitation
{
    $invitation = new UserInvitation();
    $invitation->forceFill(array_merge([
        'tenant_id' => $tenant->id,
        'email' => Str::lower(Str::random(10)).'@example.com',
        'role' => 'viewer',
        'token_hash' => hash('sha256', Str::random(40)),
        'invited_by' => null,
        'expires_at' => now()->addDays(7),
    ], $attributes))->save();

    return $invitation;
}

it('forbids every invitation endpoint without users.manage', function () {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    $manager = invtUser($tenant, 'manager');
    $invitation = invtInvite($tenant);
    Sanctum::actingAs($manager);

    $this->getJson('/api/v1/users/invitations')->assertForbidden();
    $this->postJson('/api/v1/users/invitations', ['email' => 'x@example.com', 'role' => 'sales'])->assertForbidden();
    $this->postJson("/api/v1/users/invitations/{$invitation->id}/resend")->assertForbidden();
    $this->deleteJson("/api/v1/users/invitations/{$invitation->id}")->assertForbidden();

    Notification::assertNothingSent();
    expect(UserInvitation::query()->count())->toBe(1);
});

it('creates an invitation, stores only the token hash and emails the link', function () {
    Notification::fake();
    $tenant = Tenant::factory()->create(['name' => 'Acme Test']);
    $admin = invtUser($tenant, 'admin', ['name' => 'Actor']);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/users/invitations', ['email' => '  New.Person@Example.com ', 'role' => 'sales'])
        ->assertCreated()
        ->assertJsonStructure(['id', 'email', 'role', 'role_label', 'invited_by_name', 'expires_at', 'expired', 'created_at'])
        ->assertJsonPath('email', 'new.person@example.com')
        ->assertJsonPath('role', 'sales')
        ->assertJsonPath('role_label', 'Sales')
        ->assertJsonPath('invited_by_name', 'Actor')
        ->assertJsonPath('expired', false)
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('token_hash');

    $token = null;
    Notification::assertSentOnDemand(
        UserInvitationNotification::class,
        function ($notification, $channels, $notifiable) use (&$token) {
            $token = $notification->token;

            return ($notifiable->routes['mail'] ?? null) === 'new.person@example.com'
                && $notification->tenantName === 'Acme Test'
                && $notification->roleLabel === 'Sales';
        },
    );

    $row = UserInvitation::query()->firstOrFail();
    expect($row->tenant_id)->toBe($tenant->id);
    expect($row->invited_by)->toBe($admin->id);
    expect($row->accepted_at)->toBeNull();
    expect(strlen($token))->toBe(48);
    expect($row->token_hash)->toBe(hash('sha256', $token));
    expect($row->token_hash)->not->toBe($token);
    expect($row->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue();
    expect(UserInvitationNotification::acceptUrl($token))->toEndWith('/accept-invite/'.$token);
});

it('validates the email and the role', function () {
    Notification::fake();
    $admin = invtUser(Tenant::factory()->create());
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/users/invitations', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'role']);
    $this->postJson('/api/v1/users/invitations', ['email' => 'not-an-email', 'role' => 'sales'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
    $this->postJson('/api/v1/users/invitations', ['email' => 'ok@example.com', 'role' => 'superuser'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    Notification::assertNothingSent();
    expect(UserInvitation::query()->count())->toBe(0);
});

it('refuses an email that already has an account in any tenant, with one generic message', function () {
    Notification::fake();
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = invtUser($a);
    invtUser($a, 'viewer', ['email' => 'taken@example.com']);
    invtUser($b, 'viewer', ['email' => 'elsewhere@example.com']);
    Sanctum::actingAs($admin);

    $same = $this->postJson('/api/v1/users/invitations', ['email' => 'TAKEN@example.com', 'role' => 'sales'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
    $other = $this->postJson('/api/v1/users/invitations', ['email' => 'elsewhere@example.com', 'role' => 'sales'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($other->json('errors.email'))->toBe($same->json('errors.email'));
    Notification::assertNothingSent();
    expect(UserInvitation::query()->count())->toBe(0);
});

it('re-issues the pending invitation for the same email instead of creating a second one', function () {
    Notification::fake();
    $tenant = Tenant::factory()->create();
    $admin = invtUser($tenant);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/users/invitations', ['email' => 'again@example.com', 'role' => 'viewer'])->assertCreated();
    $firstHash = UserInvitation::query()->firstOrFail()->token_hash;

    $this->postJson('/api/v1/users/invitations', ['email' => 'Again@Example.com', 'role' => 'manager'])
        ->assertOk()
        ->assertJsonPath('role', 'manager');

    expect(UserInvitation::query()->count())->toBe(1);
    $row = UserInvitation::query()->firstOrFail();
    expect($row->role)->toBe('manager');
    expect($row->token_hash)->not->toBe($firstHash);
    Notification::assertSentOnDemandTimes(UserInvitationNotification::class, 2);
});

it('lists only the pending invitations of the actors tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = invtUser($a, 'admin', ['name' => 'Actor']);
    invtInvite($a, ['email' => 'older@example.com', 'invited_by' => $admin->id, 'created_at' => now()->subMinutes(5)]);
    invtInvite($a, ['email' => 'expired@example.com', 'expires_at' => now()->subDay(), 'created_at' => now()->subMinute()]);
    invtInvite($a, ['email' => 'accepted@example.com', 'accepted_at' => now()]);
    invtInvite($b, ['email' => 'other-tenant@example.com']);
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/users/invitations')
        ->assertOk()
        ->assertJsonStructure(['data' => ['*' => ['id', 'email', 'role', 'role_label', 'invited_by_name', 'expires_at', 'expired', 'created_at']]])
        ->assertJsonMissingPath('data.0.token_hash');

    $rows = collect($response->json('data'));
    expect($rows->pluck('email')->all())->toBe(['expired@example.com', 'older@example.com']);
    expect($rows->firstWhere('email', 'expired@example.com')['expired'])->toBeTrue();
    expect($rows->firstWhere('email', 'older@example.com')['expired'])->toBeFalse();
    expect($rows->firstWhere('email', 'older@example.com')['invited_by_name'])->toBe('Actor');
});

it('resends with a fresh token and expiry, and never touches another tenants invitation', function () {
    Notification::fake();
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = invtUser($a);
    $stale = invtInvite($a, ['email' => 'late@example.com', 'role' => 'sales', 'expires_at' => now()->subDay()]);
    $foreign = invtInvite($b, ['email' => 'foreign@example.com']);
    $accepted = invtInvite($a, ['email' => 'done@example.com', 'accepted_at' => now()]);
    $oldHash = $stale->token_hash;
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/users/invitations/{$stale->id}/resend")
        ->assertOk()
        ->assertJsonPath('email', 'late@example.com')
        ->assertJsonPath('role', 'sales')
        ->assertJsonPath('expired', false);

    $fresh = UserInvitation::query()->findOrFail($stale->id);
    expect($fresh->token_hash)->not->toBe($oldHash);
    expect($fresh->expires_at->isFuture())->toBeTrue();
    Notification::assertSentOnDemandTimes(UserInvitationNotification::class, 1);

    $this->postJson("/api/v1/users/invitations/{$foreign->id}/resend")->assertNotFound();
    $this->postJson("/api/v1/users/invitations/{$accepted->id}/resend")->assertNotFound();
    Notification::assertSentOnDemandTimes(UserInvitationNotification::class, 1);
    expect(UserInvitation::query()->findOrFail($foreign->id)->expires_at->isFuture())->toBeTrue();
});

it('revokes a pending invitation, frees the email, and never touches another tenants invitation', function () {
    Notification::fake();
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $admin = invtUser($a);
    $pending = invtInvite($a, ['email' => 'gone@example.com']);
    $foreign = invtInvite($b, ['email' => 'foreign@example.com']);
    $accepted = invtInvite($a, ['email' => 'done@example.com', 'accepted_at' => now()]);
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/users/invitations/{$pending->id}")->assertNoContent();
    expect(UserInvitation::query()->whereKey($pending->id)->exists())->toBeFalse();

    $this->deleteJson("/api/v1/users/invitations/{$foreign->id}")->assertNotFound();
    $this->deleteJson("/api/v1/users/invitations/{$accepted->id}")->assertNotFound();
    expect(UserInvitation::query()->whereKey($foreign->id)->exists())->toBeTrue();
    expect(UserInvitation::query()->whereKey($accepted->id)->exists())->toBeTrue();

    $this->postJson('/api/v1/users/invitations', ['email' => 'gone@example.com', 'role' => 'viewer'])->assertCreated();
});
