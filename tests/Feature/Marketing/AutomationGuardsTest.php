<?php

use App\Domain\Marketing\Actions\DeleteEmailTemplateAction;
use App\Domain\Marketing\Actions\DeleteSegmentAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function autmgActor(string $role = 'manager'): User
{
    $tenant = Tenant::factory()->create();
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function autmgTemplate(int $tenantId): int
{
    return DB::table('email_templates')->insertGetId([
        'tenant_id' => $tenantId, 'name' => 'T '.uniqid('', true), 'subject' => 'Hello', 'body' => 'Body',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function autmgSegment(int $tenantId): int
{
    return DB::table('segments')->insertGetId([
        'tenant_id' => $tenantId, 'name' => 'S '.uniqid('', true), 'match_mode' => 'all', 'conditions' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function autmgAutomation(int $tenantId, string $status, string $trigger = 'manual', array $config = []): int
{
    return DB::table('automations')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Auto '.uniqid('', true),
        'status' => $status,
        'trigger_type' => $trigger,
        'trigger_config' => json_encode((object) $config),
        'lock_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function autmgEmailStep(int $tenantId, int $automationId, int $templateId): void
{
    DB::table('automation_steps')->insert([
        'tenant_id' => $tenantId, 'automation_id' => $automationId, 'position' => 0, 'type' => 'send_email',
        'config' => json_encode(['email_template_id' => $templateId]), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function autmgEnroll(int $tenantId, int $automationId, string $email, string $status): void
{
    DB::table('automation_enrollments')->insert([
        'tenant_id' => $tenantId, 'automation_id' => $automationId, 'email' => $email, 'email_normalized' => $email,
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('autmg: a template used by an active automation cannot be deleted until it is paused', function () {
    $actor = autmgActor();
    $templateId = autmgTemplate($actor->tenant_id);
    $automation = autmgAutomation($actor->tenant_id, 'active');
    autmgEmailStep($actor->tenant_id, $automation, $templateId);
    $other = autmgTemplate($actor->tenant_id);

    $delete = app(DeleteEmailTemplateAction::class);

    expect(fn () => $delete($actor, $templateId))->toThrow(ValidationException::class);
    expect(EmailTemplate::query()->find($templateId))->not->toBeNull();

    // A template no active automation uses is deletable.
    $delete($actor, $other);
    expect(EmailTemplate::query()->find($other))->toBeNull();

    DB::table('automations')->where('id', $automation)->update(['status' => 'paused']);
    $delete($actor, $templateId);
    expect(EmailTemplate::query()->find($templateId))->toBeNull();
});

it('autmg: a segment used by an active segment_entered automation cannot be deleted until it is paused', function () {
    $actor = autmgActor();
    $segmentId = autmgSegment($actor->tenant_id);
    $automation = autmgAutomation($actor->tenant_id, 'active', 'segment_entered', ['segment_id' => $segmentId]);
    $unused = autmgSegment($actor->tenant_id);

    $delete = app(DeleteSegmentAction::class);

    expect(fn () => $delete->execute($actor, $segmentId))->toThrow(ValidationException::class);
    expect(Segment::query()->find($segmentId))->not->toBeNull();

    $delete->execute($actor, $unused);
    expect(Segment::query()->find($unused))->toBeNull();

    DB::table('automations')->where('id', $automation)->update(['status' => 'paused']);
    $delete->execute($actor, $segmentId);
    expect(Segment::query()->find($segmentId))->toBeNull();
});

it('autmg: the API returns enrollment counts per automation, tenant-scoped, in list and show', function () {
    $other = autmgActor();
    $theirs = autmgAutomation($other->tenant_id, 'active');
    autmgEnroll($other->tenant_id, $theirs, 'x@example.test', 'active');

    $actor = autmgActor('viewer');
    $busy = autmgAutomation($actor->tenant_id, 'active');
    $quiet = autmgAutomation($actor->tenant_id, 'draft');
    autmgEnroll($actor->tenant_id, $busy, 'a@example.test', 'active');
    autmgEnroll($actor->tenant_id, $busy, 'b@example.test', 'active');
    autmgEnroll($actor->tenant_id, $busy, 'c@example.test', 'completed');
    autmgEnroll($actor->tenant_id, $busy, 'd@example.test', 'failed');

    Sanctum::actingAs($actor);

    $list = $this->getJson('/api/v1/automations')->assertOk();
    $byId = collect($list->json('data'))->keyBy('id');

    expect($byId)->toHaveCount(2);
    expect($byId[$busy]['enrollments'])->toEqual(['active' => 2, 'completed' => 1, 'cancelled' => 0, 'failed' => 1, 'total' => 4]);
    expect($byId[$quiet]['enrollments'])->toEqual(['active' => 0, 'completed' => 0, 'cancelled' => 0, 'failed' => 0, 'total' => 0]);

    $show = $this->getJson("/api/v1/automations/{$busy}")->assertOk();
    expect($show->json('enrollments'))->toEqual(['active' => 2, 'completed' => 1, 'cancelled' => 0, 'failed' => 1, 'total' => 4]);
});