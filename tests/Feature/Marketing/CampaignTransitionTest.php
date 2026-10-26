<?php

use App\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Domain\Marketing\Actions\DeleteSegmentAction;
use App\Domain\Marketing\Actions\TransitionCampaignAction;
use App\Domain\Marketing\Exceptions\CampaignTransitionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function cmptrSetup(string $role = 'manager'): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $user];
}

function cmptrSegment(int $tenantId): int
{
    return DB::table('segments')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Seg '.Str::random(8),
        'match_mode' => 'all',
        'conditions' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmptrTemplate(int $tenantId): int
{
    return DB::table('email_templates')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Tpl '.Str::random(8),
        'subject' => 'Hello {{ first_name }}',
        'body' => 'Hi {{ first_name }}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cmptrCampaign(int $tenantId, array $overrides = []): int
{
    return DB::table('campaigns')->insertGetId(array_merge([
        'tenant_id' => $tenantId,
        'name' => 'Camp '.Str::random(8),
        'status' => 'draft',
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** A campaign with a segment and a template, ready to schedule or send. */
function cmptrReady(int $tenantId, array $overrides = []): int
{
    return cmptrCampaign($tenantId, array_merge([
        'segment_id' => cmptrSegment($tenantId),
        'email_template_id' => cmptrTemplate($tenantId),
    ], $overrides));
}

function cmptrRow(int $id): object
{
    return DB::table('campaigns')->where('id', $id)->first();
}

it('schedules a draft campaign and bumps lock_version', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrReady($tenant->id);
    Sanctum::actingAs($user);

    $at = now()->addDay();
    $this->postJson("/api/v1/campaigns/{$id}/transition", [
        'to_status' => 'scheduled',
        'lock_version' => 0,
        'scheduled_at' => $at->toIso8601String(),
    ])
        ->assertOk()
        ->assertJsonPath('status', 'scheduled')
        ->assertJsonPath('lock_version', 1);

    $row = cmptrRow($id);
    expect($row->status)->toBe('scheduled')
        ->and((int) $row->lock_version)->toBe(1)
        ->and($row->scheduled_at)->not->toBeNull();
});

it('sends a draft now and stamps started_at', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrReady($tenant->id);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'sending', 'lock_version' => 0])
        ->assertOk()
        ->assertJsonPath('status', 'sending')
        ->assertJsonPath('lock_version', 1);

    expect(cmptrRow($id)->started_at)->not->toBeNull();
});

it('returns 409 with the current state on a lock_version mismatch and changes nothing', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrReady($tenant->id, ['lock_version' => 3]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'sending', 'lock_version' => 0])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_campaign')
        ->assertJsonPath('current.status', 'draft')
        ->assertJsonPath('current.lock_version', 3);

    expect(cmptrRow($id)->status)->toBe('draft');
});

it('refuses a transition the map does not allow', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrReady($tenant->id, ['status' => 'cancelled']);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'sending', 'lock_version' => 0])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_transition');

    expect(cmptrRow($id)->status)->toBe('cancelled');
});

it('refuses to schedule or send without a segment and a template', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrCampaign($tenant->id);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'sending', 'lock_version' => 0])
        ->assertStatus(422)
        ->assertJsonPath('code', 'audience_missing');

    expect(cmptrRow($id)->status)->toBe('draft');
});

it('refuses a schedule time in the past and a missing schedule time', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrReady($tenant->id);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", [
        'to_status' => 'scheduled',
        'lock_version' => 0,
        'scheduled_at' => now()->subHour()->toIso8601String(),
    ])->assertStatus(422)->assertJsonPath('code', 'schedule_invalid');

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'scheduled', 'lock_version' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('scheduled_at');

    expect(cmptrRow($id)->status)->toBe('draft');
});

it('unschedules back to draft and clears scheduled_at', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrReady($tenant->id, ['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'draft', 'lock_version' => 0])
        ->assertOk()
        ->assertJsonPath('status', 'draft');

    expect(cmptrRow($id)->scheduled_at)->toBeNull();
});

it('does not let a user set the system-only statuses', function () {
    [$tenant, $user] = cmptrSetup();
    $id = cmptrReady($tenant->id, ['status' => 'sending']);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'sent', 'lock_version' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('to_status');

    expect(cmptrRow($id)->status)->toBe('sending');
});

it('forbids roles without marketing.manage', function (string $role) {
    [$tenant, $user] = cmptrSetup($role);
    $id = cmptrReady($tenant->id);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$id}/transition", ['to_status' => 'sending', 'lock_version' => 0])
        ->assertForbidden();

    expect(cmptrRow($id)->status)->toBe('draft');
})->with(['sales', 'viewer']);

it('returns 404 for another tenant\'s campaign', function () {
    [$tenant, $user] = cmptrSetup();
    $other = Tenant::factory()->create();
    $foreign = cmptrReady($other->id);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/campaigns/{$foreign}/transition", ['to_status' => 'sending', 'lock_version' => 0])
        ->assertNotFound();

    expect(cmptrRow($foreign)->status)->toBe('draft');
});

it('lets the system finish a sending campaign but never move it backwards', function () {
    [$tenant] = cmptrSetup();
    $id = cmptrReady($tenant->id, ['status' => 'sending']);
    $action = app(TransitionCampaignAction::class);

    $sent = $action->executeSystem($tenant->id, $id, 'sent');

    expect($sent->status)->toBe('sent')
        ->and($sent->sent_at)->not->toBeNull()
        ->and($sent->completed_at)->not->toBeNull()
        ->and((int) $sent->lock_version)->toBe(1);

    expect(fn () => $action->executeSystem($tenant->id, $id, 'sending'))
        ->toThrow(CampaignTransitionException::class);
});

it('refuses to delete a segment used by a scheduled campaign', function () {
    [$tenant, $user] = cmptrSetup();
    $segmentId = cmptrSegment($tenant->id);
    cmptrCampaign($tenant->id, ['status' => 'scheduled', 'segment_id' => $segmentId, 'scheduled_at' => now()->addDay()]);

    expect(fn () => app(DeleteSegmentAction::class)->execute($user, $segmentId))
        ->toThrow(ValidationException::class);

    expect(DB::table('segments')->where('id', $segmentId)->exists())->toBeTrue();
});

it('deletes a segment used only by a draft and leaves that draft without an audience', function () {
    [$tenant, $user] = cmptrSetup();
    $segmentId = cmptrSegment($tenant->id);
    $campaignId = cmptrCampaign($tenant->id, ['segment_id' => $segmentId]);

    app(DeleteSegmentAction::class)->execute($user, $segmentId);

    expect(DB::table('segments')->where('id', $segmentId)->exists())->toBeFalse()
        ->and(cmptrRow($campaignId)->segment_id)->toBeNull();
});