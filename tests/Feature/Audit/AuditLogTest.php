<?php

use App\Domain\Audit\AuditRecorder;
use App\Domain\Deals\States\Lead;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Deal;
use App\Models\DealStageAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function audtSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

/** @param array<string, mixed> $attributes */
function audtUser(Tenant $tenant, string $role = 'admin', array $attributes = []): User
{
    audtSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function audtDealLog(Tenant $tenant, User $actor, string $dealName, string $from, string $to, $at): void
{
    audtSwitch($tenant);

    $deal = Deal::create([
        'board_id' => 1,
        'name' => $dealName,
        'owner_id' => $actor->id,
        'amount' => 1000,
        'expected_close_date' => now()->addMonth(),
        'state' => Lead::class,
    ]);

    DealStageAuditLog::create([
        'deal_id' => $deal->id,
        'user_id' => $actor->id,
        'from_state' => $from,
        'to_state' => $to,
        'transitioned_at' => $at,
    ]);
}

function audtEvent(Tenant $tenant, ?User $actor, string $event, string $label, $at): void
{
    DB::table('audit_events')->insert([
        'tenant_id' => $tenant->id,
        'user_id' => $actor?->id,
        'source' => 'users',
        'event' => $event,
        'subject_type' => 'user',
        'subject_id' => $actor?->id,
        'subject_label' => $label,
        'properties' => json_encode(['role' => 'sales']),
        'created_at' => $at,
    ]);
}

it('gives audit.view to administrators only', function () {
    expect(RoleCatalog::permissionsFor('admin'))->toContain('audit.view');

    foreach (['manager', 'sales', 'viewer'] as $role) {
        expect(RoleCatalog::permissionsFor($role))->not->toContain('audit.view');
    }
});

it('forbids the audit endpoints without audit.view and requires sign-in', function () {
    $tenant = Tenant::factory()->create();
    $manager = audtUser($tenant, 'manager');

    $this->getJson('/api/v1/audit-log')->assertUnauthorized();

    Sanctum::actingAs($manager);
    $this->getJson('/api/v1/audit-log')->assertForbidden();
    $this->getJson('/api/v1/audit-log/actors')->assertForbidden();
});

it('merges both sources newest first with the full entry shape', function () {
    $tenant = Tenant::factory()->create();
    $admin = audtUser($tenant, 'admin', ['name' => 'Ada']);

    audtDealLog($tenant, $admin, 'Big deal', 'Lead', 'Qualified', now()->subHours(3));
    audtEvent($tenant, $admin, 'user.role_changed', 'Bob', now()->subHours(1));
    audtDealLog($tenant, $admin, 'Older deal', 'Qualified', 'Proposal', now()->subHours(2));

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/audit-log')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'source', 'event', 'occurred_at', 'actor' => ['id', 'name'], 'subject' => ['type', 'id', 'label'], 'details']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('meta.total', 3);

    $rows = $response->json('data');
    expect(array_keys($rows[0]))->toEqualCanonicalizing(['id', 'source', 'event', 'occurred_at', 'actor', 'subject', 'details']);
    expect(collect($rows)->pluck('event')->all())->toBe(['user.role_changed', 'deal.stage_changed', 'deal.stage_changed']);
    expect($rows[0]['actor']['name'])->toBe('Ada');
    expect($rows[0]['details']['role'])->toBe('sales');
    expect($rows[1]['subject']['label'])->toBe('Older deal');
    expect($rows[1]['details'])->toEqualCanonicalizing(['from' => 'Qualified', 'to' => 'Proposal']);
});

it('only ever shows the actors own tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $adminA = audtUser($a, 'admin', ['name' => 'Ada']);
    $adminB = audtUser($b, 'admin', ['name' => 'Mallory']);

    audtDealLog($a, $adminA, 'A deal', 'Lead', 'Qualified', now()->subHour());
    audtEvent($a, $adminA, 'user.deactivated', 'A subject', now()->subMinutes(30));
    audtDealLog($b, $adminB, 'B-secret deal', 'Lead', 'Won', now()->subHour());
    audtEvent($b, $adminB, 'user.deactivated', 'B-secret subject', now()->subMinutes(30));

    Sanctum::actingAs($adminA);

    $response = $this->getJson('/api/v1/audit-log')->assertOk()->assertJsonPath('meta.total', 2);
    expect($response->getContent())->not->toContain('B-secret')->not->toContain('Mallory');

    // Asking for the other tenant's actor id returns nothing: the tenant predicate is not client-controlled.
    $this->getJson('/api/v1/audit-log?user_id='.$adminB->id)->assertOk()->assertJsonPath('meta.total', 0);

    $actors = $this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data');
    expect(collect($actors)->pluck('name')->all())->toBe(['Ada']);
});

it('filters by source, user and date range', function () {
    $tenant = Tenant::factory()->create();
    $admin = audtUser($tenant, 'admin', ['name' => 'Ada']);
    $bob = audtUser($tenant, 'manager', ['name' => 'Bob']);

    audtDealLog($tenant, $admin, 'Old deal', 'Lead', 'Qualified', now()->subDays(10));
    audtDealLog($tenant, $admin, 'New deal', 'Lead', 'Qualified', now()->subHour());
    audtEvent($tenant, $bob, 'user.invited', 'x', now()->subHours(2));

    Sanctum::actingAs($admin);

    $total = fn (string $query) => $this->getJson("/api/v1/audit-log?{$query}")->assertOk()->json('meta.total');

    expect($total('source=deal_stage'))->toBe(2);
    expect($total('source=users'))->toBe(1);
    expect($total('user_id='.$bob->id))->toBe(1);
    expect($total('user_id='.$admin->id))->toBe(2);
    expect($total('from='.now()->subDays(2)->toDateString()))->toBe(2);
    expect($total('to='.now()->subDays(5)->toDateString()))->toBe(1);
});

it('paginates the merged feed', function () {
    $tenant = Tenant::factory()->create();
    $admin = audtUser($tenant);

    foreach (range(1, 5) as $i) {
        audtEvent($tenant, $admin, 'user.invited', "n{$i}", now()->subMinutes($i));
    }

    Sanctum::actingAs($admin);

    $page1 = $this->getJson('/api/v1/audit-log?per_page=2&page=1')->assertOk();
    $page3 = $this->getJson('/api/v1/audit-log?per_page=2&page=3')->assertOk();

    expect($page1->json('meta.total'))->toBe(5);
    expect($page1->json('meta.last_page'))->toBe(3);
    expect(collect($page1->json('data'))->pluck('subject.label')->all())->toBe(['n1', 'n2']);
    expect(collect($page3->json('data'))->pluck('subject.label')->all())->toBe(['n5']);
});

it('rejects malformed filters', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(audtUser($tenant));

    $this->getJson('/api/v1/audit-log?source=bogus')->assertStatus(422)->assertJsonValidationErrors('source');
    $this->getJson('/api/v1/audit-log?from=2026-10-10&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
    $this->getJson('/api/v1/audit-log?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

it('records events through the recorder', function () {
    $tenant = Tenant::factory()->create();
    $admin = audtUser($tenant, 'admin', ['name' => 'Ada']);

    AuditRecorder::record($tenant->id, $admin->id, 'user.role_changed', 'user', $admin->id, 'Bob', ['from' => 'viewer', 'to' => 'sales']);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/audit-log')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'user.role_changed')
        ->assertJsonPath('data.0.details.from', 'viewer')
        ->assertJsonPath('data.0.details.to', 'sales');
});
