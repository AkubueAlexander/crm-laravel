<?php

use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function quotwfSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function quotwfUser(Tenant $tenant, ?string $role = 'manager'): User
{
    quotwfSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** Default lines: A (standard tax) + B (zero tax) = total 71.50. Pass [] for an empty quote. */
function quotwfSeed(User $actor, string $title = 'Deal', ?array $lines = null): int
{
    quotwfSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => $title,
        'currency' => 'USD',
        'lines' => $lines ?? [
            ['name' => 'A', 'quantity' => 2, 'unit_price' => '10.00', 'tax_category' => 'standard'],
            ['name' => 'B', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero'],
        ],
    ])->id;
}

it('quotwf walks a quote from draft to accepted with the right people', function () {
    $tenant = Tenant::factory()->create();
    $sales = quotwfUser($tenant, 'sales');
    $manager = quotwfUser($tenant, 'manager');
    $id = quotwfSeed($sales);
    $go = fn (string $to, int $lock, array $extra = []) => $this->postJson("/api/v1/quotes/{$id}/transition", array_merge(['to_status' => $to, 'lock_version' => $lock], $extra));

    Sanctum::actingAs($sales);
    $submitted = $go('pending_approval', 0)
        ->assertOk()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('decided_at', null)
        ->assertJsonMissingPath('tenant_id');
    expect($submitted->json('submitted_at'))->not->toBeNull();

    $go('approved', 1)->assertForbidden(); // sales cannot approve
    $go('rejected', 1, ['note' => 'no'])->assertForbidden();

    Sanctum::actingAs($manager);
    $approved = $go('approved', 1)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('lock_version', 2);
    expect($approved->json('decided_at'))->not->toBeNull();

    Sanctum::actingAs($sales);
    $sent = $go('sent', 2)->assertOk()->assertJsonPath('status', 'sent')->assertJsonPath('lock_version', 3);
    expect($sent->json('sent_at'))->not->toBeNull();

    $go('accepted', 3)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('lock_version', 4);
    $go('sent', 4)->assertStatus(422)->assertJsonPath('code', 'invalid_transition'); // terminal
});

it('quotwf enforces permissions and tenant isolation', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = quotwfUser($a);
    $viewerA = quotwfUser($a, 'viewer');
    $mine = quotwfSeed($managerA);
    $theirs = quotwfSeed(quotwfUser($b));

    Sanctum::actingAs($viewerA);
    $this->postJson("/api/v1/quotes/{$mine}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0])->assertForbidden();

    Sanctum::actingAs($managerA);
    $this->postJson("/api/v1/quotes/{$theirs}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0])->assertNotFound();

    quotwfSwitch($b);
    expect(DB::table('quotes')->where('id', $theirs)->value('status'))->toBe('draft');
});

it('quotwf answers a stale lock_version with 409 and the current quote', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $id = quotwfSeed($manager);
    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/quotes/{$id}/transition", ['to_status' => 'pending_approval', 'lock_version' => 5])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_quote')
        ->assertJsonPath('current.id', $id)
        ->assertJsonPath('current.status', 'draft')
        ->assertJsonPath('current.lock_version', 0);

    expect(DB::table('quotes')->where('id', $id)->value('status'))->toBe('draft');
});

it('quotwf rejects only with a reason, and a rejected quote can be revised', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $id = quotwfSeed($manager);
    Sanctum::actingAs($manager);
    $go = fn (string $to, int $lock, array $extra = []) => $this->postJson("/api/v1/quotes/{$id}/transition", array_merge(['to_status' => $to, 'lock_version' => $lock], $extra));

    $go('pending_approval', 0)->assertOk();
    $go('rejected', 1)->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go('rejected', 1, ['note' => '   '])->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go('rejected', 1, ['note' => 'Discount too deep'])
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('decision_note', 'Discount too deep');

    // History stays; rework happens in a new version (10.2).
    $this->postJson("/api/v1/quotes/{$id}/revise")->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('status', 'draft');
});

it('quotwf refuses illegal moves, empty quotes and unknown statuses', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $id = quotwfSeed($manager);
    $empty = quotwfSeed($manager, 'Empty', []);
    Sanctum::actingAs($manager);
    $go = fn (int $q, string $to, int $lock) => $this->postJson("/api/v1/quotes/{$q}/transition", ['to_status' => $to, 'lock_version' => $lock]);

    $go($id, 'approved', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'sent', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'draft', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'bogus', 0)->assertStatus(422)->assertJsonValidationErrors('to_status');
    $go($empty, 'pending_approval', 0)->assertStatus(422)->assertJsonPath('code', 'quote_empty');

    // Submit, then withdraw: the quote is a draft again and the submission is forgotten.
    $go($id, 'pending_approval', 0)->assertOk();
    $go($id, 'sent', 1)->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
    $go($id, 'draft', 1)->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('submitted_at', null)->assertJsonPath('lock_version', 2);
});

it('quotwf auto-approves a submission within the tenant threshold', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotwfUser($tenant);
    $small = quotwfSeed($manager, 'Small'); // total 71.50
    $big = quotwfSeed($manager, 'Big', [['name' => 'Big', 'quantity' => 1, 'unit_price' => '200.00', 'tax_category' => 'zero']]);
    Sanctum::actingAs($manager);
    $submit = fn (int $q) => $this->postJson("/api/v1/quotes/{$q}/transition", ['to_status' => 'pending_approval', 'lock_version' => 0]);

    // No threshold: every quote needs a human.
    $submit($small)->assertOk()->assertJsonPath('status', 'pending_approval');

    DB::table('tenants')->where('id', $tenant->id)->update(['quote_approval_threshold' => '100.00']);

    $auto = $submit($big)->assertOk()->assertJsonPath('status', 'pending_approval'); // 200 > 100
    expect($auto->json('decision_note'))->toBeNull();

    $small2 = quotwfSeed($manager, 'Small 2');
    $approved = $submit($small2)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('lock_version', 1);
    expect($approved->json('decision_note'))->toContain('Auto-approved');
    expect($approved->json('decided_at'))->not->toBeNull();
});
