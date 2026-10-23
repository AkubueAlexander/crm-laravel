<?php

use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\UpdateActivityAction;
use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function ovdSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

/** @return array{0: Tenant, 1: User} */
function ovdTenant(string $slug): array
{
    $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
    ovdSwitch($tenant);

    $actor = User::factory()->create(['tenant_id' => $tenant->id]);

    foreach (['activities.update', 'activities.complete'] as $name) {
        Permission::findOrCreate($name);
        $actor->givePermissionTo($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$tenant, $actor];
}

function ovdMake(Tenant $tenant, string $subject, $due = null): Activity
{
    ovdSwitch($tenant);
    $account = Account::create(['name' => 'Acme ' . $subject]);

    return Activity::create([
        'activityable_type' => 'account',
        'activityable_id' => $account->id,
        'type' => 'task',
        'subject' => $subject,
        'due_at' => $due,
    ]);
}

function ovdOverdueSubjects(): array
{
    return Activity::withoutTenantScope()
        ->whereNotNull('overdue_at')
        ->orderBy('subject')
        ->pluck('subject')
        ->all();
}

it('flags only open, live, past-due activities', function () {
    [$tenant] = ovdTenant('ovd-a');

    ovdMake($tenant, 'past-open', now()->subHour());
    ovdMake($tenant, 'future', now()->addDay());
    ovdMake($tenant, 'no-due', null);

    $done = ovdMake($tenant, 'past-completed', now()->subHour());
    $done->forceFill(['completed_at' => now()->subMinutes(5)])->save();

    $gone = ovdMake($tenant, 'past-deleted', now()->subHour());
    $gone->delete();

    $this->artisan('activities:flag-overdue')
        ->expectsOutputToContain('Flagged 1 overdue activity.')
        ->assertExitCode(0);

    expect(ovdOverdueSubjects())->toBe(['past-open']);
});

it('is idempotent and keeps the original overdue_at', function () {
    [$tenant] = ovdTenant('ovd-b');
    $a = ovdMake($tenant, 'once', now()->subHour());

    $this->artisan('activities:flag-overdue')->assertExitCode(0);
    $first = Activity::withoutTenantScope()->findOrFail($a->id)->overdue_at;
    expect($first)->not->toBeNull();

    $this->travelTo(now()->addMinutes(10));

    $this->artisan('activities:flag-overdue')
        ->expectsOutputToContain('Flagged 0 overdue activities.')
        ->assertExitCode(0);

    $second = Activity::withoutTenantScope()->findOrFail($a->id)->overdue_at;
    expect($second->equalTo($first))->toBeTrue();
});

it('flags activities across every tenant', function () {
    [$t1] = ovdTenant('ovd-c1');
    [$t2] = ovdTenant('ovd-c2');

    ovdMake($t1, 'tenant-one', now()->subHour());
    ovdMake($t2, 'tenant-two', now()->subHour());

    $this->artisan('activities:flag-overdue')
        ->expectsOutputToContain('Flagged 2 overdue activities.')
        ->assertExitCode(0);

    expect(ovdOverdueSubjects())->toBe(['tenant-one', 'tenant-two']);
});

it('is registered with the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('activities:flag-overdue')
        ->assertExitCode(0);
});

it('clears the flag on complete and on reopen', function () {
    [$tenant, $actor] = ovdTenant('ovd-d');
    $a = ovdMake($tenant, 'cycle', now()->subHour());

    $this->artisan('activities:flag-overdue')->assertExitCode(0);
    expect(Activity::findOrFail($a->id)->overdue_at)->not->toBeNull();

    $done = app(CompleteActivityAction::class)->execute($actor, Activity::findOrFail($a->id), true);
    expect($done->completed_at)->not->toBeNull()
        ->and($done->overdue_at)->toBeNull();

    // Reopened and still past due: cleared now, re-flagged by the next scheduled run.
    $reopened = app(CompleteActivityAction::class)->execute($actor, Activity::findOrFail($a->id), false);
    expect($reopened->completed_at)->toBeNull()
        ->and($reopened->overdue_at)->toBeNull();

    $this->artisan('activities:flag-overdue')->assertExitCode(0);
    expect(Activity::findOrFail($a->id)->overdue_at)->not->toBeNull();
});

it('clears the flag when due_at moves into the future, keeps it for another past date', function () {
    [$tenant, $actor] = ovdTenant('ovd-e');
    $a = ovdMake($tenant, 'moved', now()->subDays(2));

    $this->artisan('activities:flag-overdue')->assertExitCode(0);

    $stillPast = app(UpdateActivityAction::class)->execute($actor, Activity::findOrFail($a->id), [
        'due_at' => now()->subDay(),
    ]);
    expect($stillPast->overdue_at)->not->toBeNull();

    $future = app(UpdateActivityAction::class)->execute($actor, Activity::findOrFail($a->id), [
        'due_at' => now()->addDays(3),
    ]);
    expect($future->overdue_at)->toBeNull();

    $this->artisan('activities:flag-overdue')->assertExitCode(0);
    expect(Activity::findOrFail($a->id)->overdue_at)->toBeNull();
});

it('clears the flag when due_at is removed', function () {
    [$tenant, $actor] = ovdTenant('ovd-f');
    $a = ovdMake($tenant, 'no-longer-due', now()->subHour());

    $this->artisan('activities:flag-overdue')->assertExitCode(0);

    $updated = app(UpdateActivityAction::class)->execute($actor, Activity::findOrFail($a->id), [
        'due_at' => null,
    ]);
    expect($updated->overdue_at)->toBeNull();
});
