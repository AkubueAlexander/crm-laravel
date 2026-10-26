<?php

use App\Domain\Marketing\Actions\SaveAutomationAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function autmapiActor(string $role = 'manager'): User
{
    $tenant = \App\Models\Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function autmapiTemplate(int $tenantId, string $name = 'Welcome'): int
{
    return DB::table('email_templates')->insertGetId([
        'tenant_id' => $tenantId, 'name' => $name, 'subject' => 'Hello', 'body' => 'Body',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function autmapiPayload(int $templateId, array $override = []): array
{
    return array_merge([
        'name' => 'Welcome series',
        'description' => null,
        'trigger_type' => 'contact_created',
        'trigger_config' => [],
        'steps' => [
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
            ['type' => 'delay', 'config' => ['amount' => 2, 'unit' => 'days']],
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
        ],
    ], $override);
}

function autmapiCreate(User $actor, int $templateId, array $override = []): \App\Models\Automation
{
    return app(SaveAutomationAction::class)->create($actor, autmapiPayload($templateId, $override));
}

/** @return list<string> */
function autmapiKeys(): array
{
    return ['id', 'name', 'description', 'status', 'trigger_type', 'trigger_config', 'lock_version', 'steps', 'created_at', 'updated_at'];
}

it('autmapi: any marketing viewer can read the catalog', function () {
    Sanctum::actingAs(autmapiActor('viewer'));

    $res = $this->getJson('/api/v1/automations/catalog')->assertOk();

    expect(array_column($res->json('data.triggers'), 'type'))->toContain('segment_entered')
        ->and(array_column($res->json('data.steps'), 'type'))->toBe(['delay', 'send_email']);
});

it('autmapi: store creates a draft and returns exactly the whitelisted shape', function () {
    $actor = autmapiActor();
    $templateId = autmapiTemplate($actor->tenant_id);
    Sanctum::actingAs($actor);

    $res = $this->postJson('/api/v1/automations', autmapiPayload($templateId))->assertCreated();

    expect(array_keys($res->json()))->toEqualCanonicalizing(autmapiKeys())
        ->and($res->json('status'))->toBe('draft')
        ->and($res->json('lock_version'))->toBe(0)
        ->and($res->json('trigger_config'))->toBe([])
        ->and(array_column($res->json('steps'), 'position'))->toBe([1, 2, 3]);

    // {} on the wire, not [].
    expect($res->getContent())->toContain('"trigger_config":{}');
});

it('autmapi: store returns per-row errors and refuses non-managers', function () {
    $actor = autmapiActor();
    $templateId = autmapiTemplate($actor->tenant_id);
    Sanctum::actingAs($actor);

    $this->postJson('/api/v1/automations', autmapiPayload($templateId, [
        'steps' => [
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
            ['type' => 'delay', 'config' => ['amount' => 0, 'unit' => 'days']],
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors(['steps.1.config.amount']);

    $this->postJson('/api/v1/automations', autmapiPayload($templateId, ['name' => '']))->assertStatus(422)->assertJsonValidationErrors(['name']);

    $sales = autmapiActor('sales');
    Sanctum::actingAs($sales);
    $this->postJson('/api/v1/automations', autmapiPayload(autmapiTemplate($sales->tenant_id)))->assertForbidden();
});

it('autmapi: index is tenant-scoped, paginated and searchable', function () {
    $other = autmapiActor();
    autmapiCreate($other, autmapiTemplate($other->tenant_id), ['name' => 'Theirs']);

    $actor = autmapiActor('viewer');
    $templateId = autmapiTemplate($actor->tenant_id);
    $manager = User::factory()->create(['tenant_id' => $actor->tenant_id]);
    $manager->assignRole('manager');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    autmapiCreate($manager, $templateId, ['name' => 'Alpha welcome']);
    autmapiCreate($manager, $templateId, ['name' => 'Beta nurture']);

    Sanctum::actingAs($actor);

    $all = $this->getJson('/api/v1/automations')->assertOk();
    expect(array_column($all->json('data'), 'name'))->toEqualCanonicalizing(['Alpha welcome', 'Beta nurture'])
        ->and($all->json('meta.total'))->toBe(2);

    $found = $this->getJson('/api/v1/automations?q=NURTURE')->assertOk();
    expect(array_column($found->json('data'), 'name'))->toBe(['Beta nurture']);

    $sorted = $this->getJson('/api/v1/automations?sort=name&direction=asc')->assertOk();
    expect(array_column($sorted->json('data'), 'name'))->toBe(['Alpha welcome', 'Beta nurture']);
});

it('autmapi: show and update stay inside the tenant', function () {
    $other = autmapiActor();
    $theirs = autmapiCreate($other, autmapiTemplate($other->tenant_id), ['name' => 'Theirs']);

    $actor = autmapiActor();
    $templateId = autmapiTemplate($actor->tenant_id);
    Sanctum::actingAs($actor);

    $this->getJson("/api/v1/automations/{$theirs->id}")->assertNotFound();
    $this->putJson("/api/v1/automations/{$theirs->id}", autmapiPayload($templateId))->assertNotFound();
    $this->deleteJson("/api/v1/automations/{$theirs->id}")->assertNotFound();
    $this->postJson("/api/v1/automations/{$theirs->id}/transition", ['to_status' => 'active', 'lock_version' => 0])->assertNotFound();
});

it('autmapi: update bumps lock_version and an active automation cannot be edited or deleted', function () {
    $actor = autmapiActor();
    $templateId = autmapiTemplate($actor->tenant_id);
    $automation = autmapiCreate($actor, $templateId);
    Sanctum::actingAs($actor);

    $res = $this->putJson("/api/v1/automations/{$automation->id}", autmapiPayload($templateId, ['name' => 'Renamed']))->assertOk();
    expect($res->json('name'))->toBe('Renamed')->and($res->json('lock_version'))->toBe(1);

    $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'active', 'lock_version' => 1])->assertOk();

    $this->putJson("/api/v1/automations/{$automation->id}", autmapiPayload($templateId))->assertStatus(422)->assertJsonValidationErrors(['automation']);
    $this->deleteJson("/api/v1/automations/{$automation->id}")->assertStatus(422)->assertJsonValidationErrors(['automation']);
});

it('autmapi: destroy removes a draft', function () {
    $actor = autmapiActor();
    $automation = autmapiCreate($actor, autmapiTemplate($actor->tenant_id));
    Sanctum::actingAs($actor);

    $this->deleteJson("/api/v1/automations/{$automation->id}")->assertNoContent();

    expect(DB::table('automations')->where('id', $automation->id)->exists())->toBeFalse();
});

it('autmapi: transition walks draft -> active -> paused -> draft and bumps lock_version', function () {
    $actor = autmapiActor();
    $automation = autmapiCreate($actor, autmapiTemplate($actor->tenant_id));
    Sanctum::actingAs($actor);

    $a = $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'active', 'lock_version' => 0])->assertOk();
    expect($a->json('status'))->toBe('active')->and($a->json('lock_version'))->toBe(1)->and(count($a->json('steps')))->toBe(3);

    $p = $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'paused', 'lock_version' => 1])->assertOk();
    expect($p->json('status'))->toBe('paused');

    $d = $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'draft', 'lock_version' => 2])->assertOk();
    expect($d->json('status'))->toBe('draft')->and($d->json('lock_version'))->toBe(3);
});

it('autmapi: a stale lock_version is a 409 carrying the current automation', function () {
    $actor = autmapiActor();
    $automation = autmapiCreate($actor, autmapiTemplate($actor->tenant_id));
    Sanctum::actingAs($actor);

    $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'active', 'lock_version' => 0])->assertOk();

    $res = $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'paused', 'lock_version' => 0])->assertStatus(409);

    expect($res->json('code'))->toBe('stale_automation')
        ->and($res->json('current.status'))->toBe('active')
        ->and($res->json('current.lock_version'))->toBe(1);
});

it('autmapi: invalid transitions and unknown targets are refused', function () {
    $actor = autmapiActor();
    $automation = autmapiCreate($actor, autmapiTemplate($actor->tenant_id));
    Sanctum::actingAs($actor);

    $res = $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'paused', 'lock_version' => 0])->assertStatus(422);
    expect($res->json('code'))->toBe('invalid_transition');

    $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'bogus', 'lock_version' => 0])->assertStatus(422);
});

it('autmapi: activating is refused when a step template has been deleted since', function () {
    $actor = autmapiActor();
    $templateId = autmapiTemplate($actor->tenant_id);
    $automation = autmapiCreate($actor, $templateId);
    DB::table('email_templates')->where('id', $templateId)->delete();
    Sanctum::actingAs($actor);

    $res = $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'active', 'lock_version' => 0])->assertStatus(422);

    expect($res->json('code'))->toBe('automation_invalid');
    expect(DB::table('automations')->where('id', $automation->id)->value('status'))->toBe('draft');
});

it('autmapi: viewers and sales cannot transition', function () {
    $actor = autmapiActor();
    $automation = autmapiCreate($actor, autmapiTemplate($actor->tenant_id));

    $viewer = User::factory()->create(['tenant_id' => $actor->tenant_id]);
    $viewer->assignRole('viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($viewer);

    $this->postJson("/api/v1/automations/{$automation->id}/transition", ['to_status' => 'active', 'lock_version' => 0])->assertForbidden();
});