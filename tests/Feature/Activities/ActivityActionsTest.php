<?php

use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\CreateActivityAction;
use App\Domain\Activities\Actions\DeleteActivityAction;
use App\Domain\Activities\Actions\UpdateActivityAction;
use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// Helpers are global functions: keep the actv prefix unique.
function actvTenant(string $slug): Tenant
{
    return Tenant::create(['name' => strtoupper($slug), 'slug' => $slug]);
}

function actvSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
}

/** @param list<string> $permissions */
function actvActor(Tenant $tenant, array $permissions): User
{
    actvSwitch($tenant);

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::create([
        'name' => 'Actor ' . fake()->unique()->numerify('####'),
        'email' => fake()->unique()->safeEmail(),
        'tenant_id' => $tenant->id,
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);
    $user->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function actvTarget(string $alias, ?Tenant $tenant = null): Model
{
    if ($tenant !== null) {
        actvSwitch($tenant);
    }

    return match ($alias) {
        'lead' => Lead::factory()->create(),
        'contact' => Contact::factory()->create(),
        'account' => Account::create(['name' => 'Target Co']),
        'deal' => Deal::create([
            'name' => 'Target Deal',
            'owner_id' => User::query()->value('id'),
            'amount' => 0,
            'expected_close_date' => now()->addMonth()->toDateString(),
        ]),
    };
}

/** @param array<string, mixed> $data */
function actvMake(User $actor, string $alias, Model $target, array $data = []): Activity
{
    return (new CreateActivityAction())->execute($actor, $alias, (int) $target->getKey(), $data + [
        'type' => 'task',
        'subject' => 'Follow up',
    ]);
}

const ACTV_ALL = [
    'activities.view', 'activities.create', 'activities.update', 'activities.complete', 'activities.delete',
    'leads.view', 'contacts.view', 'accounts.view', 'deals.view',
];

it('creates an activity on every whitelisted target type for the actor tenant', function (string $alias) {
    $tenant = actvTenant('a');
    $actor = actvActor($tenant, ACTV_ALL);
    $target = actvTarget($alias);

    $activity = actvMake($actor, $alias, $target, ['type' => 'call', 'subject' => 'Intro call', 'tenant_id' => 999]);

    expect($activity->tenant_id)->toEqual($tenant->id)
        ->and($activity->activityable_type)->toBe($alias)
        ->and($activity->activityable_id)->toEqual($target->getKey())
        ->and($activity->owner_id)->toEqual($actor->id)
        ->and($activity->completed_at)->toBeNull()
        ->and($activity->activityable->is($target))->toBeTrue();
})->with(['lead', 'contact', 'account', 'deal']);

it('rejects any target type that is not on the whitelist, including class names', function (string $bad) {
    $tenant = actvTenant('a');
    $actor = actvActor($tenant, ACTV_ALL);
    $lead = actvTarget('lead');

    expect(fn () => (new CreateActivityAction())->execute($actor, $bad, (int) $lead->id, [
        'type' => 'note', 'subject' => 'x',
    ]))->toThrow(ValidationException::class);

    expect(Activity::withoutTenantScope()->count())->toBe(0);
})->with(['App\\Models\\User', 'users', 'LEAD', '']);

it('rejects a target record that belongs to another tenant and creates nothing', function () {
    $a = actvTenant('a');
    $b = actvTenant('b');
    $actorA = actvActor($a, ACTV_ALL);
    $leadB = actvTarget('lead', $b);
    actvSwitch($a);

    expect(fn () => actvMake($actorA, 'lead', $leadB))->toThrow(ValidationException::class);
    expect(Activity::withoutTenantScope()->count())->toBe(0);
});

it('rejects an unknown or soft-deleted target record', function () {
    $tenant = actvTenant('a');
    $actor = actvActor($tenant, ACTV_ALL);
    $lead = actvTarget('lead');
    $lead->delete();

    expect(fn () => actvMake($actor, 'lead', $lead))->toThrow(ValidationException::class);
    expect(fn () => (new CreateActivityAction())->execute($actor, 'lead', 999999, [
        'type' => 'note', 'subject' => 'x',
    ]))->toThrow(ValidationException::class);
});

it('requires activities.create and view permission on the target', function () {
    $tenant = actvTenant('a');
    $lead = actvTarget('lead', $tenant);

    $noCreate = actvActor($tenant, ['leads.view']);
    expect(fn () => actvMake($noCreate, 'lead', $lead))->toThrow(AuthorizationException::class);

    $noView = actvActor($tenant, ['activities.create']);
    expect(fn () => actvMake($noView, 'lead', $lead))->toThrow(AuthorizationException::class);

    expect(Activity::withoutTenantScope()->count())->toBe(0);
});

it('rejects an owner from another tenant', function () {
    $a = actvTenant('a');
    $b = actvTenant('b');
    $actorB = actvActor($b, []);
    $actorA = actvActor($a, ACTV_ALL);
    $lead = actvTarget('lead', $a);

    expect(fn () => actvMake($actorA, 'lead', $lead, ['owner_id' => $actorB->id]))
        ->toThrow(ValidationException::class);
});

it('rejects an invalid activity type', function () {
    $tenant = actvTenant('a');
    $actor = actvActor($tenant, ACTV_ALL);
    $lead = actvTarget('lead');

    expect(fn () => actvMake($actor, 'lead', $lead, ['type' => 'carrier-pigeon']))
        ->toThrow(ValidationException::class);
});

it('never lets one tenant see another tenant activity', function () {
    $a = actvTenant('a');
    $b = actvTenant('b');
    $actorB = actvActor($b, ACTV_ALL);
    $leadB = actvTarget('lead');
    $activityB = actvMake($actorB, 'lead', $leadB);

    actvSwitch($a);

    expect(Activity::find($activityB->id))->toBeNull();
    expect(Activity::count())->toBe(0);
});

it('updates only editable fields and ignores target, tenant and completion', function () {
    $tenant = actvTenant('a');
    $actor = actvActor($tenant, ACTV_ALL);
    $target = actvTarget('lead');
    $other = actvTarget('lead');
    $activity = actvMake($actor, 'lead', $target);

    (new UpdateActivityAction())->execute($actor, $activity, [
        'subject' => 'Renamed',
        'activityable_type' => 'deal',
        'activityable_id' => $other->id,
        'tenant_id' => 9999,
        'completed_at' => now()->toDateTimeString(),
    ]);

    $fresh = $activity->fresh();
    expect($fresh->subject)->toBe('Renamed')
        ->and($fresh->activityable_type)->toBe('lead')
        ->and($fresh->activityable_id)->toEqual($target->id)
        ->and($fresh->tenant_id)->toEqual($tenant->id)
        ->and($fresh->completed_at)->toBeNull();
});

it('forbids update without activities.update', function () {
    $tenant = actvTenant('a');
    $owner = actvActor($tenant, ACTV_ALL);
    $viewer = actvActor($tenant, ['activities.view']);
    $activity = actvMake($owner, 'lead', actvTarget('lead'));

    expect(fn () => (new UpdateActivityAction())->execute($viewer, $activity, ['subject' => 'Nope']))
        ->toThrow(AuthorizationException::class);
});

it('completes, is idempotent, and reopens', function () {
    $tenant = actvTenant('a');
    $actor = actvActor($tenant, ACTV_ALL);
    $activity = actvMake($actor, 'lead', actvTarget('lead'));
    $complete = new CompleteActivityAction();

    $complete->execute($actor, $activity);
    $first = $activity->fresh()->completed_at;
    expect($first)->not->toBeNull();

    // The instance passed in is stale on purpose: the action re-reads under a lock.
    $complete->execute($actor, $activity);
    expect($activity->fresh()->completed_at->equalTo($first))->toBeTrue();

    $complete->execute($actor, $activity, false);
    expect($activity->fresh()->completed_at)->toBeNull();
});

it('forbids completing without activities.complete', function () {
    $tenant = actvTenant('a');
    $owner = actvActor($tenant, ACTV_ALL);
    $viewer = actvActor($tenant, ['activities.update']);
    $activity = actvMake($owner, 'lead', actvTarget('lead'));

    expect(fn () => (new CompleteActivityAction())->execute($viewer, $activity))
        ->toThrow(AuthorizationException::class);
});

it('soft deletes an activity and forbids deleting without activities.delete', function () {
    $tenant = actvTenant('a');
    $actor = actvActor($tenant, ACTV_ALL);
    $weak = actvActor($tenant, ['activities.view']);
    $activity = actvMake($actor, 'lead', actvTarget('lead'));

    expect(fn () => (new DeleteActivityAction())->execute($weak, $activity))
        ->toThrow(AuthorizationException::class);

    (new DeleteActivityAction())->execute($actor, $activity);

    expect(Activity::find($activity->id))->toBeNull();
    expect(Activity::withTrashed()->find($activity->id))->not->toBeNull();
});
