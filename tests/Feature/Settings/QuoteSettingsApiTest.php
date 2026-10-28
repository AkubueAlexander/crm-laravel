<?php

use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function qsetSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function qsetUser(Tenant $tenant, ?string $role = 'admin'): User
{
    qsetSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A one-line zero-tax quote with a total of 50.00. */
function qsetQuote(User $actor): int
{
    qsetSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Threshold test',
        'currency' => 'USD',
        'lines' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero']],
    ])->id;
}

it('qset reads, sets and clears the approval threshold', function () {
    $tenant = Tenant::factory()->create();
    $admin = qsetUser($tenant);
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/settings/quotes')->assertOk()->assertJsonPath('approval_threshold', null);

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => '150'])
        ->assertOk()->assertJsonPath('approval_threshold', '150.00');
    expect((float) DB::table('tenants')->where('id', $tenant->id)->value('quote_approval_threshold'))->toBe(150.0);

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => 99.5])
        ->assertOk()->assertJsonPath('approval_threshold', '99.50');
    $this->getJson('/api/v1/settings/quotes')->assertOk()->assertJsonPath('approval_threshold', '99.50');

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => '0'])
        ->assertOk()->assertJsonPath('approval_threshold', '0.00');

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => null])
        ->assertOk()->assertJsonPath('approval_threshold', null);
    expect(DB::table('tenants')->where('id', $tenant->id)->value('quote_approval_threshold'))->toBeNull();

    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => ''])
        ->assertOk()->assertJsonPath('approval_threshold', null);
});

it('qset validates the amount', function () {
    $tenant = Tenant::factory()->create();
    Sanctum::actingAs(qsetUser($tenant));

    $this->putJson('/api/v1/settings/quotes', [])->assertStatus(422)->assertJsonValidationErrors('approval_threshold');

    foreach (['abc', '-5', '1.234', '1e3', '12345678901234567890123'] as $bad) {
        $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => $bad])
            ->assertStatus(422)->assertJsonValidationErrors('approval_threshold');
    }

    expect(DB::table('tenants')->where('id', $tenant->id)->value('quote_approval_threshold'))->toBeNull();
});

it('qset needs settings.manage and never touches another tenant', function () {
    $b = Tenant::factory()->create();
    DB::table('tenants')->where('id', $b->id)->update(['quote_approval_threshold' => '500.00']);

    $a = Tenant::factory()->create();
    $manager = qsetUser($a, 'manager');
    $sales = qsetUser($a, 'sales');
    $admin = qsetUser($a);

    foreach ([$manager, $sales] as $user) {
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/settings/quotes')->assertForbidden();
        $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => '10'])->assertForbidden();
    }

    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => '10'])->assertOk();

    expect((float) DB::table('tenants')->where('id', $b->id)->value('quote_approval_threshold'))->toBe(500.0);
    expect((float) DB::table('tenants')->where('id', $a->id)->value('quote_approval_threshold'))->toBe(10.0);
});

it('qset changes what happens when a quote is submitted', function () {
    $tenant = Tenant::factory()->create();
    $admin = qsetUser($tenant);
    $manager = qsetUser($tenant, 'manager');
    $first = qsetQuote($manager);
    $second = qsetQuote($manager);

    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => '100'])->assertOk();

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/quotes/{$first}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0])
        ->assertOk()->assertJsonPath('status', 'approved'); // 50.00 <= 100.00

    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/settings/quotes', ['approval_threshold' => null])->assertOk();

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/quotes/{$second}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0])
        ->assertOk()->assertJsonPath('status', 'pending_approval'); // no threshold: a human decides
});
