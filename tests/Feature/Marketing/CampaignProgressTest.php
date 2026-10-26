<?php

use App\Broadcasting\CampaignsChannel;
use App\Domain\Marketing\Actions\TransitionCampaignAction;
use App\Domain\Marketing\Sending\CampaignProgressBroadcaster;
use App\Events\CampaignProgressUpdated;
use App\Jobs\SendCampaignChunkJob;
use App\Listeners\BroadcastCampaignJobProgress;
use App\Models\User;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/** @return array{0: object, 1: User} */
function cmpprgActor(?string $role = 'admin', ?object $tenant = null): array
{
    $tenant ??= \App\Models\Tenant::factory()->create();
    \App\Domain\Tenant\TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    \App\Domain\Users\RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $user];
}

function cmpprgCampaign(object $tenant, User $user, string $status = 'sending', array $counts = []): int
{
    return (int) DB::table('campaigns')->insertGetId(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Progress test',
        'subject' => 'Hello',
        'status' => $status,
        'created_by' => $user->id,
        'lock_version' => 0,
        'recipients_count' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $counts));
}

function cmpprgDeactivate(int $userId): void
{
    foreach (['deactivated_at', 'disabled_at'] as $column) {
        if (Schema::hasColumn('users', $column)) {
            DB::table('users')->where('id', $userId)->update([$column => now()]);

            return;
        }
    }
    foreach (['is_active', 'active'] as $column) {
        if (Schema::hasColumn('users', $column)) {
            DB::table('users')->where('id', $userId)->update([$column => false]);

            return;
        }
    }
    throw new RuntimeException('Unknown deactivation column on users: extend cmpprgDeactivate and CampaignsChannel.');
}

function cmpprgJobEvent(string $kind, string $serializedCommand): object
{
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn(['data' => ['command' => $serializedCommand]]);

    return $kind === 'failed'
        ? new JobFailed('redis', $job, new RuntimeException('boom'))
        : new JobProcessed('redis', $job);
}

it('broadcasts only counters and status on the tenant campaigns private channel', function () {
    $event = new CampaignProgressUpdated(5, 9, 'sending', 3, 100, 40, 2);

    expect($event->broadcastAs())->toBe('campaign.progress');
    expect($event->broadcastOn()[0]->name)->toBe('private-tenant.5.campaigns');
    expect($event->broadcastWith())->toBe([
        'id' => 9,
        'status' => 'sending',
        'lock_version' => 3,
        'recipients_count' => 100,
        'sent_count' => 40,
        'failed_count' => 2,
    ]);
});

it('authorizes the campaigns channel for an active same-tenant user with marketing.view', function () {
    [$tenant, $user] = cmpprgActor('admin');

    expect((new CampaignsChannel)->join($user, $tenant->id))->toBeTrue();

    [$tenant2, $viewer] = cmpprgActor('viewer', $tenant);
    expect((new CampaignsChannel)->join($viewer, $tenant2->id))->toBeTrue();
});

it('denies the campaigns channel to another tenant, a deactivated user and a user without marketing.view', function () {
    [$tenantA, $userA] = cmpprgActor('admin');
    [$tenantB, $userB] = cmpprgActor('admin');

    // Another tenant's user asking for tenant A's channel.
    expect((new CampaignsChannel)->join($userB, $tenantA->id))->toBeFalse();

    // A user with no role holds no marketing.view.
    [$tenantC, $noRole] = cmpprgActor(null);
    expect((new CampaignsChannel)->join($noRole, $tenantC->id))->toBeFalse();

    // Deactivated after the session was established: re-verified in the DB at subscribe time.
    [$tenantD, $soon] = cmpprgActor('admin');
    expect((new CampaignsChannel)->join($soon, $tenantD->id))->toBeTrue();
    cmpprgDeactivate($soon->id);
    expect((new CampaignsChannel)->join($soon, $tenantD->id))->toBeFalse();
});

it('registers the channel and discovers the queue listener', function () {
    $channels = file_get_contents(base_path('routes/channels.php'));
    expect($channels)->toContain('tenant.{tenantId}.campaigns');

    expect(app('events')->getListeners(JobProcessed::class))->not->toBeEmpty();
    expect(app('events')->getListeners(JobFailed::class))->not->toBeEmpty();
});

it('broadcasts the new status after a transition', function () {
    Event::fake([CampaignProgressUpdated::class]);
    [$tenant, $admin] = cmpprgActor('admin');
    $id = cmpprgCampaign($tenant, $admin, 'scheduled');

    app(TransitionCampaignAction::class)->execute($admin, $id, 'cancelled', 0, null);

    Event::assertDispatched(
        CampaignProgressUpdated::class,
        fn (CampaignProgressUpdated $e) => $e->campaignId === $id
            && $e->tenantId === $tenant->id
            && $e->status === 'cancelled'
            && $e->lockVersion === 1,
    );
});

it('broadcasts counters after a processed chunk job and after a failed one', function () {
    Event::fake([CampaignProgressUpdated::class]);
    [$tenant, $admin] = cmpprgActor('admin');
    $id = cmpprgCampaign($tenant, $admin, 'sending', ['recipients_count' => 60, 'sent_count' => 25, 'failed_count' => 1]);

    $command = serialize(new SendCampaignChunkJob($tenant->id, $id));
    $listener = app(BroadcastCampaignJobProgress::class);

    $listener->handleProcessed(cmpprgJobEvent('processed', $command));
    $listener->handleFailed(cmpprgJobEvent('failed', $command));

    Event::assertDispatchedTimes(CampaignProgressUpdated::class, 2);
    Event::assertDispatched(
        CampaignProgressUpdated::class,
        fn (CampaignProgressUpdated $e) => $e->campaignId === $id
            && $e->status === 'sending'
            && $e->recipientsCount === 60
            && $e->sentCount === 25
            && $e->failedCount === 1,
    );
});

it('ignores unrelated jobs and unknown campaigns', function () {
    Event::fake([CampaignProgressUpdated::class]);
    [$tenant] = cmpprgActor('admin');
    $listener = app(BroadcastCampaignJobProgress::class);

    $listener->handleProcessed(cmpprgJobEvent('processed', serialize(new stdClass)));
    $listener->handleProcessed(cmpprgJobEvent('processed', serialize(new SendCampaignChunkJob($tenant->id, 987654))));

    Event::assertNotDispatched(CampaignProgressUpdated::class);
});

it('never lets a broadcast outage escape', function () {
    [$tenant, $admin] = cmpprgActor('admin');
    $id = cmpprgCampaign($tenant, $admin, 'sending');

    Event::listen(CampaignProgressUpdated::class, function () {
        throw new RuntimeException('reverb is down');
    });

    CampaignProgressBroadcaster::broadcast($tenant->id, $id);

    $command = serialize(new SendCampaignChunkJob($tenant->id, $id));
    app(BroadcastCampaignJobProgress::class)->handleProcessed(cmpprgJobEvent('processed', $command));

    expect(true)->toBeTrue();
});