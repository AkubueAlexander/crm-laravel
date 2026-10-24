<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

const INVTP_PASSWORD = 'correct-horse-battery-1';

/**
 * Creates an invitation whose raw token this test knows.
 *
 * @param array<string, mixed> $attributes
 * @return array{0: UserInvitation, 1: string}
 */
function invtpIssue(Tenant $tenant, array $attributes = []): array
{
    $token = Str::random(48);
    $invitation = new UserInvitation();
    $invitation->forceFill(array_merge([
        'tenant_id' => $tenant->id,
        'email' => 'invitee@example.com',
        'role' => 'sales',
        'token_hash' => hash('sha256', $token),
        'invited_by' => null,
        'expires_at' => now()->addDays(7),
    ], $attributes))->save();

    return [$invitation, $token];
}

/** @return array<string, string> */
function invtpHeaders(): array
{
    return ['Referer' => 'http://localhost:5173'];
}

/** @param array<string, mixed> $overrides */
function invtpAccept(object $test, string $token, array $overrides = [])
{
    return $test->withHeaders(invtpHeaders())->postJson("/api/v1/invitations/{$token}/accept", array_merge([
        'name' => 'New Person',
        'password' => INVTP_PASSWORD,
        'password_confirmation' => INVTP_PASSWORD,
    ], $overrides));
}

it('previews a usable invitation without signing in, exposing only what the page needs', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme Test']);
    [, $token] = invtpIssue($tenant);

    $response = $this->getJson("/api/v1/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('email', 'invitee@example.com')
        ->assertJsonPath('tenant_name', 'Acme Test')
        ->assertJsonPath('role', 'sales')
        ->assertJsonPath('role_label', 'Sales');

    expect(array_keys($response->json()))->toEqualCanonicalizing(['email', 'tenant_name', 'role', 'role_label', 'expires_at']);
});

it('answers every unusable link with the same 410', function () {
    $tenant = Tenant::factory()->create();
    [, $used] = invtpIssue($tenant, ['email' => 'used@example.com', 'accepted_at' => now()]);
    [, $expired] = invtpIssue($tenant, ['email' => 'expired@example.com', 'expires_at' => now()->subMinute()]);
    [, $ghostRole] = invtpIssue($tenant, ['email' => 'ghost@example.com', 'role' => 'ghost']);
    $unknown = Str::random(48);

    $bodies = [];
    foreach ([$used, $expired, $ghostRole, $unknown] as $token) {
        $bodies[] = $this->getJson("/api/v1/invitations/{$token}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'invitation_invalid')
            ->json();
        invtpAccept($this, $token)->assertStatus(410)->assertJsonPath('code', 'invitation_invalid');
    }

    expect(collect($bodies)->unique()->count())->toBe(1);
    expect(User::query()->count())->toBe(0);

    // A link that cannot even be a token is rejected by the route itself.
    $this->getJson('/api/v1/invitations/short')->assertNotFound();
});

it('creates the account in the invitations tenant with the invited role, signs in, and burns the link', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    [$invitation, $token] = invtpIssue($a, ['email' => 'Invitee@Example.com', 'role' => 'sales']);

    // Nothing the client sends may change the tenant, email or role of the account.
    invtpAccept($this, $token, ['tenant_id' => $b->id, 'email' => 'attacker@example.com', 'role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('message', 'Invitation accepted.');

    $this->assertAuthenticated('web');

    $user = User::query()->where('email', 'invitee@example.com')->firstOrFail();
    expect($user->tenant_id)->toBe($a->id);
    expect($user->name)->toBe('New Person');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->deactivated_at)->toBeNull();
    expect(Hash::check(INVTP_PASSWORD, $user->password))->toBeTrue();
    expect($user->password)->not->toBe(INVTP_PASSWORD);
    expect(User::query()->where('email', 'attacker@example.com')->exists())->toBeFalse();

    TenantContext::set($a);
    setPermissionsTeamId($a->id);
    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->getRoleNames()->all())->toBe(['sales']);
    expect($fresh->can('contacts.create'))->toBeTrue();
    expect($fresh->can('users.manage'))->toBeFalse();

    expect($invitation->fresh()->accepted_at)->not->toBeNull();

    // Single use: the same link is dead now, and creates nothing.
    invtpAccept($this, $token)->assertStatus(410)->assertJsonPath('code', 'invitation_invalid');
    $this->getJson("/api/v1/invitations/{$token}")->assertStatus(410);
    expect(User::query()->count())->toBe(1);
});

it('validates the name and password and leaves the invitation pending', function () {
    $tenant = Tenant::factory()->create();
    [$invitation, $token] = invtpIssue($tenant);

    invtpAccept($this, $token, ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
    invtpAccept($this, $token, ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
    invtpAccept($this, $token, ['password_confirmation' => 'does-not-match-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(User::query()->count())->toBe(0);
    expect($invitation->fresh()->accepted_at)->toBeNull();
    $this->getJson("/api/v1/invitations/{$token}")->assertOk();
});

it('refuses a link whose email has meanwhile got an account, and creates nothing', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    [$invitation, $token] = invtpIssue($a, ['email' => 'shared@example.com']);

    // The same address registered through another tenant's invitation first.
    TenantContext::set($b);
    setPermissionsTeamId($b->id);
    RoleCatalog::provision($b);
    User::factory()->create(['tenant_id' => $b->id, 'email' => 'Shared@Example.com']);
    TenantContext::clear();

    $this->getJson("/api/v1/invitations/{$token}")->assertStatus(410)->assertJsonPath('code', 'invitation_invalid');
    invtpAccept($this, $token)->assertStatus(410)->assertJsonPath('code', 'invitation_invalid');

    expect(User::query()->where('tenant_id', $a->id)->count())->toBe(0);
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

it('lets the new user sign in again with the password they chose', function () {
    $tenant = Tenant::factory()->create();
    [, $token] = invtpIssue($tenant, ['email' => 'again@example.com']);

    invtpAccept($this, $token)->assertOk();
    $this->withHeaders(invtpHeaders())->postJson('/api/v1/logout')->assertOk();

    $this->withHeaders(invtpHeaders())
        ->postJson('/api/v1/login', ['email' => 'again@example.com', 'password' => INVTP_PASSWORD])
        ->assertOk();
});
