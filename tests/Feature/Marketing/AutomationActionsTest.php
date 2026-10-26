<?php

use App\Domain\Marketing\Actions\DeleteAutomationAction;
use App\Domain\Marketing\Actions\SaveAutomationAction;
use App\Domain\Marketing\Automations\AutomationDsl;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function autmActor(string $role = 'manager'): User
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

function autmTemplate(int $tenantId, string $name = 'Welcome'): int
{
    return DB::table('email_templates')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => $name,
        'subject' => 'Hello',
        'body' => 'Body',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function autmSegment(int $tenantId, string $name = 'Everyone'): int
{
    return DB::table('segments')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => $name,
        'match_mode' => 'all',
        'conditions' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function autmPayload(int $templateId, array $override = []): array
{
    return array_merge([
        'name' => 'Welcome series',
        'description' => 'Onboarding drip',
        'trigger_type' => 'contact_created',
        'trigger_config' => [],
        'steps' => [
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
            ['type' => 'delay', 'config' => ['amount' => '2', 'unit' => 'days']],
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId, 'junk' => 'x']],
        ],
    ], $override);
}

/** @return array<string, string> */
function autmErrors(callable $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return array_map(fn ($messages) => $messages[0], $e->errors());
    }

    return [];
}

it('autm: the catalog lists triggers, step types and limits', function () {
    $catalog = AutomationDsl::catalog();

    expect(array_column($catalog['triggers'], 'type'))->toBe(['manual', 'contact_created', 'lead_created', 'segment_entered']);
    expect(array_column($catalog['steps'], 'type'))->toBe(['delay', 'send_email']);
    expect($catalog['limits'])->toBe(['max_steps' => 20, 'max_delay_days' => 90]);
});

it('autm: creates a draft with normalized, ordered steps owned by the actor tenant', function () {
    $actor = autmActor();
    $templateId = autmTemplate($actor->tenant_id);

    $automation = app(SaveAutomationAction::class)->create($actor, autmPayload($templateId));

    expect($automation->status)->toBe('draft')
        ->and($automation->lock_version)->toBe(0)
        ->and((int) $automation->tenant_id)->toBe((int) $actor->tenant_id)
        ->and((int) $automation->created_by)->toBe((int) $actor->id);

    $rows = DB::table('automation_steps')->where('automation_id', $automation->id)->orderBy('position')->get();

    expect($rows->pluck('position')->all())->toBe([1, 2, 3])
        ->and($rows->pluck('type')->all())->toBe(['send_email', 'delay', 'send_email'])
        ->and(json_decode($rows[1]->config, true))->toEqual(['amount' => 2, 'unit' => 'days'])
        ->and(json_decode($rows[2]->config, true))->toEqual(['email_template_id' => $templateId])
        ->and($rows->pluck('tenant_id')->unique()->all())->toBe([(int) $actor->tenant_id]);
});

it('autm: accepts a segment trigger for an own segment only', function () {
    $actor = autmActor();
    $templateId = autmTemplate($actor->tenant_id);
    $segmentId = autmSegment($actor->tenant_id);

    $ok = app(SaveAutomationAction::class)->create($actor, autmPayload($templateId, [
        'trigger_type' => 'segment_entered',
        'trigger_config' => ['segment_id' => $segmentId, 'junk' => 1],
    ]));

    expect($ok->trigger_config)->toBe(['segment_id' => $segmentId]);

    $foreignTenant = \App\Models\Tenant::factory()->create();
    $foreignSegment = autmSegment($foreignTenant->id, 'Theirs');

    $errors = autmErrors(fn () => app(SaveAutomationAction::class)->create($actor, autmPayload($templateId, [
        'name' => 'Other',
        'trigger_type' => 'segment_entered',
        'trigger_config' => ['segment_id' => $foreignSegment],
    ])));

    expect($errors)->toHaveKey('trigger_config.segment_id');
});

it('autm: rejects a foreign tenant template, bad delays, an empty list and a trailing wait', function () {
    $actor = autmActor();
    $templateId = autmTemplate($actor->tenant_id);
    $foreignTenant = \App\Models\Tenant::factory()->create();
    $foreignTemplate = autmTemplate($foreignTenant->id, 'Theirs');
    $save = app(SaveAutomationAction::class);

    $errors = autmErrors(fn () => $save->create($actor, autmPayload($templateId, [
        'steps' => [['type' => 'send_email', 'config' => ['email_template_id' => $foreignTemplate]]],
    ])));
    expect($errors)->toHaveKey('steps.0.config.email_template_id');

    $errors = autmErrors(fn () => $save->create($actor, autmPayload($templateId, [
        'steps' => [
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
            ['type' => 'delay', 'config' => ['amount' => 91, 'unit' => 'days']],
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
        ],
    ])));
    expect($errors)->toHaveKey('steps.1.config.amount');

    $errors = autmErrors(fn () => $save->create($actor, autmPayload($templateId, ['steps' => []])));
    expect($errors)->toHaveKey('steps');

    $errors = autmErrors(fn () => $save->create($actor, autmPayload($templateId, [
        'steps' => [
            ['type' => 'send_email', 'config' => ['email_template_id' => $templateId]],
            ['type' => 'delay', 'config' => ['amount' => 1, 'unit' => 'hours']],
        ],
    ])));
    expect($errors['steps'])->toContain('cannot end with a wait');

    $errors = autmErrors(fn () => $save->create($actor, autmPayload($templateId, [
        'trigger_type' => 'nope',
        'steps' => [['type' => 'sms', 'config' => []]],
    ])));
    expect($errors)->toHaveKeys(['trigger_type', 'steps.0.type']);

    expect(DB::table('automations')->count())->toBe(0);
});

it('autm: refuses to create without marketing.manage', function () {
    $actor = autmActor('sales');
    $templateId = autmTemplate($actor->tenant_id);

    expect(fn () => app(SaveAutomationAction::class)->create($actor, autmPayload($templateId)))
        ->toThrow(AuthorizationException::class);
});

it('autm: rejects a duplicate name case-insensitively', function () {
    $actor = autmActor();
    $templateId = autmTemplate($actor->tenant_id);
    $save = app(SaveAutomationAction::class);

    $save->create($actor, autmPayload($templateId));

    $errors = autmErrors(fn () => $save->create($actor, autmPayload($templateId, ['name' => 'WELCOME SERIES'])));

    expect($errors)->toHaveKey('name');
});

it('autm: update replaces the steps and bumps lock_version', function () {
    $actor = autmActor();
    $templateId = autmTemplate($actor->tenant_id);
    $save = app(SaveAutomationAction::class);
    $automation = $save->create($actor, autmPayload($templateId));

    $updated = $save->update($actor, (int) $automation->id, autmPayload($templateId, [
        'name' => 'Renamed',
        'steps' => [['type' => 'send_email', 'config' => ['email_template_id' => $templateId]]],
    ]));

    expect($updated->name)->toBe('Renamed')
        ->and($updated->lock_version)->toBe(1)
        ->and(DB::table('automation_steps')->where('automation_id', $automation->id)->count())->toBe(1);
});

it('autm: an active automation cannot be edited or deleted, and other tenants get a 404', function () {
    $actor = autmActor();
    $templateId = autmTemplate($actor->tenant_id);
    $save = app(SaveAutomationAction::class);
    $automation = $save->create($actor, autmPayload($templateId));

    DB::table('automations')->where('id', $automation->id)->update(['status' => 'active']);

    expect(autmErrors(fn () => $save->update($actor, (int) $automation->id, autmPayload($templateId))))->toHaveKey('automation');
    expect(autmErrors(fn () => app(DeleteAutomationAction::class)->execute($actor, (int) $automation->id)))->toHaveKey('automation');

    DB::table('automations')->where('id', $automation->id)->update(['status' => 'paused']);

    $other = autmActor();
    $otherTemplate = autmTemplate($other->tenant_id);

    expect(fn () => $save->update($other, (int) $automation->id, autmPayload($otherTemplate)))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(DeleteAutomationAction::class)->execute($other, (int) $automation->id))->toThrow(ModelNotFoundException::class);
    expect(DB::table('automations')->where('id', $automation->id)->exists())->toBeTrue();
});

it('autm: delete removes the automation and its steps', function () {
    $actor = autmActor();
    $templateId = autmTemplate($actor->tenant_id);
    $automation = app(SaveAutomationAction::class)->create($actor, autmPayload($templateId));

    app(DeleteAutomationAction::class)->execute($actor, (int) $automation->id);

    expect(DB::table('automations')->where('id', $automation->id)->exists())->toBeFalse()
        ->and(DB::table('automation_steps')->where('automation_id', $automation->id)->exists())->toBeFalse();
});