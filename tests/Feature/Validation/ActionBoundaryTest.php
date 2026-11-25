<?php

use App\Domain\Forecasting\PipelineProbabilities;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Settings\Actions\UpdatePipelineSettingsAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Lead;
use App\Models\PipelineStageSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function aboundSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function aboundActor(Tenant $tenant): User
{
    aboundSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('abound lead create re-validates server-side and ignores stray keys', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorB = aboundActor($b);
    $actorA = aboundActor($a);
    $create = app(CreateLeadAction::class);

    expect(fn () => $create->execute($actorA, ['first_name' => ' ']))->toThrow(ValidationException::class);
    expect(fn () => $create->execute($actorA, ['first_name' => 'Ada', 'email' => 'not-an-email']))->toThrow(ValidationException::class);
    expect(fn () => $create->execute($actorA, ['first_name' => 'Ada', 'status' => 'converted']))->toThrow(ValidationException::class);
    expect(fn () => $create->execute($actorA, ['first_name' => 'Ada', 'owner_id' => $actorB->id]))->toThrow(ValidationException::class);

    expect(Lead::query()->count())->toBe(0);

    $lead = $create->execute($actorA, ['first_name' => 'Ada', 'tenant_id' => $b->id, 'converted_deal_id' => 99]);
    expect((int) $lead->tenant_id)->toBe($a->id)
        ->and($lead->status)->toBe('new')
        ->and($lead->converted_deal_id)->toBeNull();
});

it('abound lead update refuses converted and a foreign owner', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorB = aboundActor($b);
    $actorA = aboundActor($a);
    $lead = Lead::factory()->create();
    $update = app(UpdateLeadAction::class);

    expect(fn () => $update->execute($actorA, $lead, ['status' => 'converted']))->toThrow(ValidationException::class);
    expect(fn () => $update->execute($actorA, $lead, ['owner_id' => $actorB->id]))->toThrow(ValidationException::class);

    expect($lead->fresh()->status)->not->toBe('converted');

    $updated = $update->execute($actorA, $lead, ['status' => 'qualified']);
    expect($updated->status)->toBe('qualified');
});

it('abound pipeline settings refuse out-of-range and non-numeric probabilities', function () {
    $tenant = Tenant::factory()->create();
    $actor = aboundActor($tenant);
    $slug = array_key_first(PipelineProbabilities::DEFAULTS);
    $save = app(UpdatePipelineSettingsAction::class);

    expect(fn () => $save->execute($actor, [$slug => 5]))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, [$slug => -0.1]))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, [$slug => 'abc']))->toThrow(ValidationException::class);

    expect(PipelineStageSetting::query()->count())->toBe(0);
});