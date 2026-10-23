<?php

use App\Broadcasting\UserChannel;
use App\Domain\Tenant\TenantContext;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\ActivityOverdueNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;

function ntfTenant(string $slug)
{
    $class = class_exists(\App\Models\Tenant::class)
        ? \App\Models\Tenant::class
        : \App\Domain\Tenant\Tenant::class;

    $tenant = $class::create(['name' => $slug, 'slug' => $slug]);
    ntfSwitch($tenant);

    return $tenant;
}

function ntfSwitch($tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function ntfMake(?int $ownerId, $due, bool $completed = false): Activity
{
    $lead = Lead::factory()->create();

    $activity = Activity::create([
        'activityable_type' => 'lead',
        'activityable_id' => $lead->id,
        'type' => 'task',
        'subject' => 'Follow up',
        'due_at' => $due,
        'owner_id' => $ownerId,
    ]);

    if ($completed) {
        $activity->forceFill(['completed_at' => now()])->save();
    }

    return $activity;
}

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
});

it('notifies the owner once and does not duplicate on re-run', function () {
    $tenant = ntfTenant('ntf-once');
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $activity = ntfMake($owner->id, now()->subHour());

    $this->artisan('activities:flag-overdue')->expectsOutput('Flagged 1 overdue activity.')->assertSuccessful();
    $this->artisan('activities:flag-overdue')->expectsOutput('Flagged 0 overdue activities.')->assertSuccessful();

    $notes = $owner->notifications()->get();
    expect($notes)->toHaveCount(1);

    $n = $notes->first();
    expect($n->type)->toBe(ActivityOverdueNotification::class);
    expect($n->data['kind'])->toBe('activity_overdue');
    expect((int) $n->data['tenant_id'])->toBe((int) $tenant->id);
    expect($n->data['activity_id'])->toBe($activity->id);
    expect($n->data['target_type'])->toBe('lead');
    expect($n->read_at)->toBeNull();
});

it('flags a row with no owner but notifies nobody', function () {
    $tenant = ntfTenant('ntf-noowner');
    $activity = ntfMake(null, now()->subHour());

    $this->artisan('activities:flag-overdue')->assertSuccessful();

    expect(Activity::withoutTenantScope()->find($activity->id)->overdue_at)->not->toBeNull();
    expect(\Illuminate\Notifications\DatabaseNotification::query()->count())->toBe(0);
});

it('does not notify for completed or not-yet-due activities', function () {
    $tenant = ntfTenant('ntf-skip');
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);

    ntfMake($owner->id, now()->subHour(), true);
    ntfMake($owner->id, now()->addDay());

    $this->artisan('activities:flag-overdue')->expectsOutput('Flagged 0 overdue activities.')->assertSuccessful();

    expect($owner->notifications()->count())->toBe(0);
});

it('flags but does not notify for a stale backlog beyond the window', function () {
    $tenant = ntfTenant('ntf-stale');
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);
    $activity = ntfMake($owner->id, now()->subDays(30));

    $this->artisan('activities:flag-overdue')->assertSuccessful();

    expect(Activity::withoutTenantScope()->find($activity->id)->overdue_at)->not->toBeNull();
    expect($owner->notifications()->count())->toBe(0);
});

it('never notifies an owner from a different tenant', function () {
    $b = ntfTenant('ntf-xb');
    $userB = User::factory()->create(['tenant_id' => $b->id]);

    $a = ntfTenant('ntf-xa');
    ntfMake($userB->id, now()->subHour());

    $this->artisan('activities:flag-overdue')->assertSuccessful();

    expect($userB->notifications()->count())->toBe(0);
});

it('keeps each tenant owner notifications separate', function () {
    $a = ntfTenant('ntf-isoa');
    $userA = User::factory()->create(['tenant_id' => $a->id]);
    ntfMake($userA->id, now()->subHour());

    $b = ntfTenant('ntf-isob');
    $userB = User::factory()->create(['tenant_id' => $b->id]);
    ntfMake($userB->id, now()->subHour());

    $this->artisan('activities:flag-overdue')->assertSuccessful();

    $noteA = $userA->notifications()->get();
    $noteB = $userB->notifications()->get();

    expect($noteA)->toHaveCount(1);
    expect($noteB)->toHaveCount(1);
    expect((int) $noteA->first()->data['tenant_id'])->toBe((int) $a->id);
    expect((int) $noteB->first()->data['tenant_id'])->toBe((int) $b->id);
});

it('broadcasts on the tenant-scoped private user channel', function () {
    $tenant = ntfTenant('ntf-bcast');
    $owner = User::factory()->create(['tenant_id' => $tenant->id]);

    expect($owner->receivesBroadcastNotificationsOn(new ActivityOverdueNotification(ntfMake($owner->id, now()->addDay()))))
        ->toBe("tenant.{$tenant->id}.user.{$owner->id}");

    Event::fake([BroadcastNotificationCreated::class]);
    ntfMake($owner->id, now()->subHour());

    $this->artisan('activities:flag-overdue')->assertSuccessful();

    Event::assertDispatched(BroadcastNotificationCreated::class, function ($event) use ($tenant, $owner) {
        $names = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

        return in_array("private-tenant.{$tenant->id}.user.{$owner->id}", $names, true);
    });
});

it('authorizes the user channel only for the user in their own tenant', function () {
    $a = ntfTenant('ntf-cha');
    $userA = User::factory()->create(['tenant_id' => $a->id]);
    $other = User::factory()->create(['tenant_id' => $a->id]);

    $b = ntfTenant('ntf-chb');

    $channel = new UserChannel();

    expect($channel->join($userA, $a->id, $userA->id))->toBeTrue();
    // someone else's channel, same tenant
    expect($channel->join($userA, $a->id, $other->id))->toBeFalse();
    // right user id, wrong tenant
    expect($channel->join($userA, $b->id, $userA->id))->toBeFalse();

    // a stale in-memory tenant claim is re-checked against the database
    $moved = clone $userA;
    $moved->tenant_id = $b->id;
    expect($channel->join($moved, $b->id, $userA->id))->toBeFalse();

    // no tenant at all
    $ghost = clone $userA;
    $ghost->tenant_id = null;
    expect($channel->join($ghost, $a->id, $userA->id))->toBeFalse();
});
