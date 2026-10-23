<?php

use App\Domain\Tenant\TenantContext;
use App\Models\Account;
use App\Models\Activity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Account} */
function ovdapiSetup(string $slug): array
{
    $tenant = Tenant::create(['name' => $slug, 'slug' => $slug]);
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);

    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    foreach (['activities.view', 'accounts.view'] as $name) {
        Permission::findOrCreate($name);
        $actor->givePermissionTo($name);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$actor, Account::create(['name' => 'Acme ' . $slug])];
}

function ovdapiMake(Account $account, string $subject): Activity
{
    return Activity::create([
        'activityable_type' => 'account',
        'activityable_id' => $account->id,
        'type' => 'task',
        'subject' => $subject,
        'due_at' => now()->subHour(),
    ]);
}

function ovdapiList($test, Account $account): array
{
    return $test->getJson('/api/v1/activities?activityable_type=account&activityable_id=' . $account->id)
        ->assertOk()
        ->json('data');
}

it('exposes overdue_at and is_overdue once the scheduler has flagged a row', function () {
    [$actor, $account] = ovdapiSetup('ovdapi-a');
    ovdapiMake($account, 'late');
    Sanctum::actingAs($actor);

    $before = ovdapiList($this, $account);
    expect($before[0]['is_overdue'])->toBeFalse()
        ->and($before[0]['overdue_at'])->toBeNull();

    $this->artisan('activities:flag-overdue')->assertExitCode(0);

    $after = ovdapiList($this, $account);
    expect($after[0]['is_overdue'])->toBeTrue()
        ->and($after[0]['overdue_at'])->not->toBeNull();
});

it('never reports a completed activity as overdue', function () {
    [$actor, $account] = ovdapiSetup('ovdapi-b');
    $a = ovdapiMake($account, 'late-but-done');
    $a->forceFill(['overdue_at' => now()->subMinutes(10), 'completed_at' => now()])->save();
    Sanctum::actingAs($actor);

    $rows = ovdapiList($this, $account);
    expect($rows[0]['is_completed'])->toBeTrue()
        ->and($rows[0]['is_overdue'])->toBeFalse();
});
