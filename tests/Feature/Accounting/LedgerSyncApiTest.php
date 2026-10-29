<?php

use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Jobs\RunLedgerSyncJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function lsyncapiUser(Tenant $tenant, string $role = 'manager'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('lsyncapi requires authentication and accounting permissions', function () {
    $this->getJson('/api/v1/accounting/sync')->assertUnauthorized();

    $a = Tenant::factory()->create();
    $mgr = lsyncapiUser($a);
    $viewer = lsyncapiUser($a, 'viewer');
    $sales = lsyncapiUser($a, 'sales');
    Queue::fake();

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/accounting/sync')->assertForbidden();
    $this->getJson('/api/v1/accounting/sync/runs')->assertForbidden();

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/accounting/sync')->assertOk();
    $this->getJson('/api/v1/accounting/sync/runs')->assertOk();
    $this->putJson('/api/v1/accounting/sync/settings', ['provider' => 'xero'])->assertForbidden();
    $this->postJson('/api/v1/accounting/sync/run')->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->getJson('/api/v1/accounting/sync')->assertOk();
});

it('lsyncapi shows the status, saves the provider and validates it', function () {
    $a = Tenant::factory()->create();
    $mgr = lsyncapiUser($a);
    Sanctum::actingAs($mgr);

    $this->getJson('/api/v1/accounting/sync')
        ->assertOk()
        ->assertJsonPath('provider', 'log')
        ->assertJsonPath('last_synced_at', null)
        ->assertJsonPath('last_run', null)
        ->assertJsonPath('active_run', null)
        ->assertJsonCount(3, 'providers');

    $this->putJson('/api/v1/accounting/sync/settings', ['provider' => 'quickbooks'])->assertOk()->assertJsonPath('provider', 'quickbooks');
    $this->getJson('/api/v1/accounting/sync')->assertJsonPath('provider', 'quickbooks');

    $this->putJson('/api/v1/accounting/sync/settings', ['provider' => 'sage'])->assertUnprocessable()->assertJsonValidationErrors(['provider']);
    $this->putJson('/api/v1/accounting/sync/settings', [])->assertUnprocessable()->assertJsonValidationErrors(['provider']);
});

it('lsyncapi runs a sync once at a time and lists only this tenants runs', function () {
    Queue::fake();
    config(['logging.default' => 'null']);

    $b = Tenant::factory()->create();
    $mb = lsyncapiUser($b);
    Sanctum::actingAs($mb);
    $theirRun = $this->postJson('/api/v1/accounting/sync/run')->assertStatus(202)->json('id');

    $a = Tenant::factory()->create();
    $mgr = lsyncapiUser($a);
    Sanctum::actingAs($mgr);

    $created = $this->postJson('/api/v1/accounting/sync/run')->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonPath('provider', 'log');
    $runId = $created->json('id');
    Queue::assertPushed(RunLedgerSyncJob::class, fn ($j) => $j->tenantId === $a->id && $j->runId === $runId);

    $this->postJson('/api/v1/accounting/sync/run')->assertUnprocessable()->assertJsonValidationErrors(['sync']);
    $this->getJson('/api/v1/accounting/sync')->assertJsonPath('active_run.id', $runId)->assertJsonPath('last_synced_at', null);

    // The job finishes the run; the status now has a last_synced_at and no active run.
    (new RunLedgerSyncJob($a->id, $runId))->handle();

    $this->getJson('/api/v1/accounting/sync')
        ->assertOk()
        ->assertJsonPath('active_run', null)
        ->assertJsonPath('last_run.status', 'succeeded');
    expect($this->getJson('/api/v1/accounting/sync')->json('last_synced_at'))->not->toBeNull();

    $list = $this->getJson('/api/v1/accounting/sync/runs')->assertOk();
    expect($list->json('meta.total'))->toBe(1)->and($list->json('data.0.id'))->toBe($runId)->and($list->json('data.0.status'))->toBe('succeeded');
    expect(DB::table('ledger_sync_runs')->where('id', $theirRun)->value('tenant_id'))->toBe($b->id);

    // The next sync is allowed again.
    $this->postJson('/api/v1/accounting/sync/run')->assertStatus(202);
});
