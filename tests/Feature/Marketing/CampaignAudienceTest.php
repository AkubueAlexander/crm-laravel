<?php

use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/**
 * No factories exist for segments/templates, so insert a minimal row straight into the table, filling any
 * NOT NULL column that has no default from its type. Only tenant_id, name and timestamps matter to these tests.
 */
function cmplcRow(string $model, int $tenantId, int $userId, string $name): int
{
    $table = (new $model())->getTable();
    $row = [];

    foreach (Schema::getColumns($table) as $c) {
        $n = $c['name'];

        if ($n === 'id' || ! empty($c['auto_increment']) || ! empty($c['generation']) || $n === 'deleted_at') {
            continue;
        }

        if ($n === 'tenant_id') { $row[$n] = $tenantId; continue; }
        if ($n === 'name') { $row[$n] = $name; continue; }
        if ($n === 'created_at' || $n === 'updated_at') { $row[$n] = now(); continue; }
        if (! empty($c['nullable']) || $c['default'] !== null) { continue; }

        $type = strtolower((string) $c['type_name']);

        if ($n === 'match_mode') { $row[$n] = defined(Segment::class.'::MATCH_MODES') ? Segment::MATCH_MODES[0] : 'all'; }
        elseif (str_ends_with($n, '_by') || in_array($n, ['user_id', 'owner_id'], true)) { $row[$n] = $userId; }
        elseif (str_contains($type, 'json')) { $row[$n] = '[]'; }
        elseif (str_contains($type, 'bool')) { $row[$n] = false; }
        elseif (str_contains($type, 'int')) { $row[$n] = 0; }
        elseif (str_contains($type, 'time') || str_contains($type, 'date')) { $row[$n] = now(); }
        else { $row[$n] = 'x'; }
    }

    return (int) DB::table($table)->insertGetId($row);
}

/** A second tenant with one segment and one template. Create it BEFORE cmplcActor (which sets the context). */
function cmplcForeign(): array
{
    $tenant = \App\Models\Tenant::factory()->create();
    \App\Domain\Tenant\TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    return [
        cmplcRow(Segment::class, $tenant->id, $user->id, 'Foreign segment'),
        cmplcRow(EmailTemplate::class, $tenant->id, $user->id, 'Foreign template'),
    ];
}

function cmplcActor(string $role = 'admin'): array
{
    $tenant = \App\Models\Tenant::factory()->create();
    \App\Domain\Tenant\TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    \App\Domain\Users\RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $user];
}

function cmplcOwn($tenant, $user, string $segmentName = 'Own segment', string $templateName = 'Own template'): array
{
    return [
        cmplcRow(Segment::class, $tenant->id, $user->id, $segmentName),
        cmplcRow(EmailTemplate::class, $tenant->id, $user->id, $templateName),
    ];
}

function cmplcDraft($tenant, $user, string $name = 'Draft one', array $extra = []): int
{
    return DB::table('campaigns')->insertGetId(array_merge([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'subject' => 'Hello',
        'status' => 'draft',
        'created_by' => $user->id,
        'lock_version' => 0,
        'recipients_count' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));
}

it('creates a campaign with its own segment and template', function () {
    [$foreignSegment, $foreignTemplate] = cmplcForeign();
    [$tenant, $user] = cmplcActor();
    [$segmentId, $templateId] = cmplcOwn($tenant, $user);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/campaigns', [
        'name' => 'With audience',
        'segment_id' => $segmentId,
        'email_template_id' => $templateId,
    ])->assertCreated();

    $row = DB::table('campaigns')->where('tenant_id', $tenant->id)->where('name', 'With audience')->first();
    expect($row->segment_id)->toBe($segmentId)
        ->and($row->email_template_id)->toBe($templateId)
        ->and($row->status)->toBe('draft');
});

it('still creates a campaign when the picker keys are absent', function () {
    [$tenant, $user] = cmplcActor();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/campaigns', ['name' => 'Plain'])->assertCreated();

    $row = DB::table('campaigns')->where('tenant_id', $tenant->id)->where('name', 'Plain')->first();
    expect($row->segment_id)->toBeNull()->and($row->email_template_id)->toBeNull();
});

it('rejects another tenant segment or template on create', function () {
    [$foreignSegment, $foreignTemplate] = cmplcForeign();
    [$tenant, $user] = cmplcActor();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/campaigns', ['name' => 'Leaky', 'segment_id' => $foreignSegment])
        ->assertStatus(422)->assertJsonValidationErrors(['segment_id']);

    $this->postJson('/api/v1/campaigns', ['name' => 'Leaky', 'email_template_id' => $foreignTemplate])
        ->assertStatus(422)->assertJsonValidationErrors(['email_template_id']);

    expect(DB::table('campaigns')->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('updates, keeps and clears the audience on a draft', function () {
    [$tenant, $user] = cmplcActor();
    [$segmentId, $templateId] = cmplcOwn($tenant, $user);
    $id = cmplcDraft($tenant, $user);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/campaigns/$id", [
        'name' => 'Draft one', 'segment_id' => $segmentId, 'email_template_id' => $templateId,
    ])->assertOk();

    $row = DB::table('campaigns')->find($id);
    expect($row->segment_id)->toBe($segmentId)->and($row->email_template_id)->toBe($templateId);

    // Keys absent: untouched.
    $this->putJson("/api/v1/campaigns/$id", ['name' => 'Renamed'])->assertOk();
    $row = DB::table('campaigns')->find($id);
    expect($row->name)->toBe('Renamed')->and($row->segment_id)->toBe($segmentId)->and($row->email_template_id)->toBe($templateId);

    // Explicit null: cleared.
    $this->putJson("/api/v1/campaigns/$id", ['name' => 'Renamed', 'segment_id' => null, 'email_template_id' => null])->assertOk();
    $row = DB::table('campaigns')->find($id);
    expect($row->segment_id)->toBeNull()->and($row->email_template_id)->toBeNull();
});

it('rejects another tenant audience on update', function () {
    [$foreignSegment, $foreignTemplate] = cmplcForeign();
    [$tenant, $user] = cmplcActor();
    $id = cmplcDraft($tenant, $user);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/campaigns/$id", ['name' => 'Draft one', 'segment_id' => $foreignSegment])
        ->assertStatus(422)->assertJsonValidationErrors(['segment_id']);

    $this->putJson("/api/v1/campaigns/$id", ['name' => 'Draft one', 'email_template_id' => $foreignTemplate])
        ->assertStatus(422)->assertJsonValidationErrors(['email_template_id']);

    expect(DB::table('campaigns')->find($id)->segment_id)->toBeNull();
});

it('refuses to change the audience of a non-draft campaign', function () {
    [$tenant, $user] = cmplcActor();
    [$segmentId] = cmplcOwn($tenant, $user);
    $id = cmplcDraft($tenant, $user, 'Sent one', ['status' => 'sent']);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/campaigns/$id", ['name' => 'Sent one', 'segment_id' => $segmentId])
        ->assertStatus(422)->assertJsonValidationErrors(['status']);

    expect(DB::table('campaigns')->find($id)->segment_id)->toBeNull();
});

it('forbids a viewer from saving an audience', function () {
    [$tenant, $user] = cmplcActor('viewer');
    [$segmentId] = cmplcOwn($tenant, $user);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/campaigns', ['name' => 'Nope', 'segment_id' => $segmentId])->assertForbidden();
});

it('lists picker options for the own tenant only, by name, id and name only', function () {
    cmplcForeign();
    [$tenant, $user] = cmplcActor('viewer');
    cmplcOwn($tenant, $user, 'Zeta segment', 'Zeta template');
    cmplcOwn($tenant, $user, 'Alpha segment', 'Alpha template');
    Sanctum::actingAs($user);

    $segments = $this->getJson('/api/v1/campaigns/segment-options')->assertOk();
    expect(array_column($segments->json('data'), 'name'))->toBe(['Alpha segment', 'Zeta segment']);
    expect(array_keys($segments->json('data.0')))->toBe(['id', 'name']);

    $templates = $this->getJson('/api/v1/campaigns/template-options')->assertOk();
    expect(array_column($templates->json('data'), 'name'))->toBe(['Alpha template', 'Zeta template']);
    expect(array_keys($templates->json('data.0')))->toBe(['id', 'name']);
});

it('requires authentication for picker options', function () {
    $this->getJson('/api/v1/campaigns/segment-options')->assertUnauthorized();
    $this->getJson('/api/v1/campaigns/template-options')->assertUnauthorized();
});