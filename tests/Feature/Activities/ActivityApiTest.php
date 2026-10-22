<?php

use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\CreateActivityAction;
use App\Domain\Tenant\TenantContext;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// Helpers are global functions: keep the actapi prefix unique.
function actapiTenant(string $slug): Tenant
{
    return Tenant::create(['name' => strtoupper($slug), 'slug' => $slug]);
}

function actapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
}

/** @param list<string> $permissions */
function actapiActor(Tenant $tenant, array $permissions): User
{
    actapiSwitch($tenant);

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

/** @param array<string, mixed> $data */
function actapiMake(User $actor, string $alias, Model $target, array $data = []): Activity
{
    return (new CreateActivityAction())->execute($actor, $alias, (int) $target->getKey(), $data + [
        'type' => 'task',
        'subject' => 'Follow up',
    ]);
}

const ACTAPI_ALL = [
    'activities.view', 'activities.create', 'activities.update', 'activities.complete', 'activities.delete',
    'leads.view', 'contacts.view',
];

it('creates an activity, stamps the tenant from the session and defaults the owner', function () {
    $tenant = actapiTenant('a');
    $actor = actapiActor($tenant, ACTAPI_ALL);
    $lead = Lead::factory()->create();
    Sanctum::actingAs($actor);

    $this->postJson('/api/v1/activities', [
        'activityable_type' => 'lead',
        'activityable_id' => $lead->id,
        'type' => 'call',
        'subject' => 'Intro call',
        'tenant_id' => 999,
    ])
        ->assertCreated()
        ->assertJsonPath('activityable_type', 'lead')
        ->assertJsonPath('activityable_id', $lead->id)
        ->assertJsonPath('type', 'call')
        ->assertJsonPath('owner_id', $actor->id)
        ->assertJsonPath('is_completed', false)
        ->assertJsonMissingPath('tenant_id');

    expect(Activity::withoutTenantScope()->first()->tenant_id)->toEqual($tenant->id);
});

it('rejects invalid input, including a class name as the target type', function () {
    $tenant = actapiTenant('a');
    $actor = actapiActor($tenant, ACTAPI_ALL);
    $lead = Lead::factory()->create();
    Sanctum::actingAs($actor);

    $this->postJson('/api/v1/activities', [
        'activityable_type' => 'App\\Models\\User',
        'activityable_id' => $lead->id,
        'type' => 'carrier-pigeon',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['activityable_type', 'type', 'subject']);

    expect(Activity::withoutTenantScope()->count())->toBe(0);
});

it('forbids creating an activity without activities.create', function () {
    $tenant = actapiTenant('a');
    $actor = actapiActor($tenant, ['activities.view', 'leads.view']);
    $lead = Lead::factory()->create();
    Sanctum::actingAs($actor);

    $this->postJson('/api/v1/activities', [
        'activityable_type' => 'lead',
        'activityable_id' => $lead->id,
        'type' => 'note',
        'subject' => 'Nope',
    ])->assertForbidden();
});

it('rejects a target or an owner from another tenant', function () {
    $a = actapiTenant('a');
    $b = actapiTenant('b');
    $actorB = actapiActor($b, []);
    actapiSwitch($b);
    $leadB = Lead::factory()->create();

    $actorA = actapiActor($a, ACTAPI_ALL);
    $leadA = Lead::factory()->create();
    Sanctum::actingAs($actorA);

    $this->postJson('/api/v1/activities', [
        'activityable_type' => 'lead',
        'activityable_id' => $leadB->id,
        'type' => 'note',
        'subject' => 'Cross tenant target',
    ])->assertUnprocessable()->assertJsonValidationErrors(['activityable_id']);

    $this->postJson('/api/v1/activities', [
        'activityable_type' => 'lead',
        'activityable_id' => $leadA->id,
        'type' => 'note',
        'subject' => 'Cross tenant owner',
        'owner_id' => $actorB->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['owner_id']);

    expect(Activity::withoutTenantScope()->count())->toBe(0);
});

it('lists one record timeline newest first, paginated, with a status filter', function () {
    $tenant = actapiTenant('a');
    $actor = actapiActor($tenant, ACTAPI_ALL);
    $lead = Lead::factory()->create();
    $otherLead = Lead::factory()->create();
    $contact = Contact::factory()->create();

    $first = actapiMake($actor, 'lead', $lead, ['subject' => 'First']);
    actapiMake($actor, 'lead', $lead, ['subject' => 'Second']);
    actapiMake($actor, 'lead', $otherLead, ['subject' => 'Other lead']);
    actapiMake($actor, 'contact', $contact, ['subject' => 'Contact note']);
    (new CompleteActivityAction())->execute($actor, $first);

    Sanctum::actingAs($actor);
    $base = '/api/v1/activities?activityable_type=lead&activityable_id=' . $lead->id;

    $this->getJson($base)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.subject', 'Second')
        ->assertJsonPath('data.1.subject', 'First');

    $this->getJson($base . '&status=open')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Second');
    $this->getJson($base . '&status=completed')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'First');
});

it('forbids the timeline without activities.view or view access to the target record', function () {
    $tenant = actapiTenant('a');
    $noActivities = actapiActor($tenant, ['leads.view']);
    $noLeads = actapiActor($tenant, ['activities.view']);
    $lead = Lead::factory()->create();
    $url = '/api/v1/activities?activityable_type=lead&activityable_id=' . $lead->id;

    Sanctum::actingAs($noActivities);
    $this->getJson($url)->assertForbidden();

    Sanctum::actingAs($noLeads);
    $this->getJson($url)->assertForbidden();
});

it('validates the timeline query and returns 404 for another tenant record', function () {
    $a = actapiTenant('a');
    $b = actapiTenant('b');
    actapiActor($b, []);
    $leadB = Lead::factory()->create();
    $actorA = actapiActor($a, ACTAPI_ALL);
    Sanctum::actingAs($actorA);

    $this->getJson('/api/v1/activities')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['activityable_type', 'activityable_id']);

    $this->getJson('/api/v1/activities?activityable_type=' . urlencode('App\\Models\\User') . '&activityable_id=1')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['activityable_type']);

    $this->getJson('/api/v1/activities?activityable_type=lead&activityable_id=' . $leadB->id)
        ->assertNotFound();
});

it('updates only editable fields and ignores target, tenant and completion', function () {
    $tenant = actapiTenant('a');
    $actor = actapiActor($tenant, ACTAPI_ALL);
    $lead = Lead::factory()->create();
    $other = Lead::factory()->create();
    $activity = actapiMake($actor, 'lead', $lead);
    Sanctum::actingAs($actor);

    $this->patchJson('/api/v1/activities/' . $activity->id, [
        'subject' => 'Renamed',
        'activityable_type' => 'contact',
        'activityable_id' => $other->id,
        'tenant_id' => 9999,
        'completed_at' => now()->toIso8601String(),
    ])
        ->assertOk()
        ->assertJsonPath('subject', 'Renamed')
        ->assertJsonPath('activityable_type', 'lead')
        ->assertJsonPath('activityable_id', $lead->id)
        ->assertJsonPath('is_completed', false);

    expect($activity->fresh()->tenant_id)->toEqual($tenant->id);
});

it('returns 404 for another tenant activity on update, complete and delete', function () {
    $a = actapiTenant('a');
    $b = actapiTenant('b');
    $actorB = actapiActor($b, ACTAPI_ALL);
    $leadB = Lead::factory()->create();
    $activityB = actapiMake($actorB, 'lead', $leadB, ['subject' => 'Tenant B only']);

    $actorA = actapiActor($a, ACTAPI_ALL);
    Sanctum::actingAs($actorA);
    $url = '/api/v1/activities/' . $activityB->id;

    $this->patchJson($url, ['subject' => 'Hijacked'])->assertNotFound();
    $this->postJson($url . '/complete')->assertNotFound();
    $this->deleteJson($url)->assertNotFound();

    $row = Activity::withoutTenantScope()->find($activityB->id);
    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe('Tenant B only')
        ->and($row->completed_at)->toBeNull();
});

it('completes and reopens an activity', function () {
    $tenant = actapiTenant('a');
    $actor = actapiActor($tenant, ACTAPI_ALL);
    $activity = actapiMake($actor, 'lead', Lead::factory()->create());
    Sanctum::actingAs($actor);
    $url = '/api/v1/activities/' . $activity->id . '/complete';

    $this->postJson($url)->assertOk()->assertJsonPath('is_completed', true);
    $this->postJson($url)->assertOk()->assertJsonPath('is_completed', true);
    $this->postJson($url, ['completed' => false])->assertOk()->assertJsonPath('is_completed', false);
});

it('forbids update, complete and delete without the matching permission', function () {
    $tenant = actapiTenant('a');
    $owner = actapiActor($tenant, ACTAPI_ALL);
    $weak = actapiActor($tenant, ['activities.view', 'leads.view']);
    $activity = actapiMake($owner, 'lead', Lead::factory()->create());
    Sanctum::actingAs($weak);
    $url = '/api/v1/activities/' . $activity->id;

    $this->patchJson($url, ['subject' => 'Nope'])->assertForbidden();
    $this->postJson($url . '/complete')->assertForbidden();
    $this->deleteJson($url)->assertForbidden();

    expect($activity->fresh()->subject)->toBe('Follow up');
});

it('soft deletes an activity and drops it from the timeline', function () {
    $tenant = actapiTenant('a');
    $actor = actapiActor($tenant, ACTAPI_ALL);
    $lead = Lead::factory()->create();
    $activity = actapiMake($actor, 'lead', $lead);
    Sanctum::actingAs($actor);

    $this->deleteJson('/api/v1/activities/' . $activity->id)->assertNoContent();

    $this->getJson('/api/v1/activities?activityable_type=lead&activityable_id=' . $lead->id)
        ->assertOk()
        ->assertJsonCount(0, 'data');
    expect(Activity::withTrashed()->find($activity->id))->not->toBeNull();
});
