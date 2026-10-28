<?php

use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function quotverSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function quotverUser(Tenant $tenant, ?string $role = 'manager'): User
{
    quotverSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** Two lines: A (standard tax) and B (zero tax). Subtotal 70.00, tax 1.50, total 71.50. Optionally pushed out of draft. */
function quotverSeed(User $actor, string $title = 'Original', string $status = 'sent'): Quote
{
    quotverSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $quote = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => $title,
        'currency' => 'USD',
        'lines' => [
            ['name' => 'A', 'quantity' => 2, 'unit_price' => '10.00', 'tax_category' => 'standard'],
            ['name' => 'B', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero'],
        ],
    ]);

    if ($status !== 'draft') {
        DB::table('quotes')->where('id', $quote->id)->update(['status' => $status]);
    }

    return $quote;
}

it('quotver revises a quote that left draft into a new draft version with copied lines', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotverUser($tenant);
    $source = quotverSeed($manager);
    Sanctum::actingAs($manager);

    $response = $this->postJson("/api/v1/quotes/{$source->id}/revise")
        ->assertCreated()
        ->assertJsonPath('quote_number', 'Q-00001')
        ->assertJsonPath('version', 2)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('lock_version', 0)
        ->assertJsonPath('total', '71.50')
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('lines.0.name', 'A')
        ->assertJsonPath('lines.1.name', 'B')
        ->assertJsonMissingPath('tenant_id');

    expect($response->json('id'))->not->toBe($source->id);

    quotverSwitch($tenant);
    expect(Quote::query()->where('tenant_id', $tenant->id)->where('quote_seq', $source->quote_seq)->count())->toBe(2);
    expect(DB::table('quotes')->where('id', $source->id)->value('status'))->toBe('sent'); // history untouched
    expect(QuoteLine::query()->where('tenant_id', $tenant->id)->where('quote_id', $source->id)->count())->toBe(2);
    expect(QuoteLine::query()->where('tenant_id', $tenant->id)->where('quote_id', $response->json('id'))->count())->toBe(2);
});

it('quotver refuses to revise a draft or anything but the latest version', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotverUser($tenant);
    $draft = quotverSeed($manager, 'Draft', 'draft');
    $sent = quotverSeed($manager, 'Sent');
    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/quotes/{$draft->id}/revise")->assertStatus(422)->assertJsonValidationErrors('status');

    $v2 = $this->postJson("/api/v1/quotes/{$sent->id}/revise")->assertCreated()->json('id');

    // v1 is no longer the latest; v2 is still a draft.
    $this->postJson("/api/v1/quotes/{$sent->id}/revise")->assertStatus(422)->assertJsonValidationErrors('version');
    $this->postJson("/api/v1/quotes/{$v2}/revise")->assertStatus(422)->assertJsonValidationErrors('status');

    quotverSwitch($tenant);
    expect(Quote::query()->where('tenant_id', $tenant->id)->where('quote_seq', $sent->quote_seq)->count())->toBe(2);
});

it('quotver enforces permissions and tenant isolation', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = quotverUser($a);
    $viewerA = quotverUser($a, 'viewer');
    $noneA = quotverUser($a, null);
    $mine = quotverSeed($managerA);
    $theirs = quotverSeed(quotverUser($b));

    Sanctum::actingAs($viewerA);
    $this->postJson("/api/v1/quotes/{$mine->id}/revise")->assertForbidden();
    $this->getJson("/api/v1/quotes/{$mine->id}/versions")->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($noneA);
    $this->getJson("/api/v1/quotes/{$mine->id}/versions")->assertForbidden();

    Sanctum::actingAs($managerA);
    $this->postJson("/api/v1/quotes/{$theirs->id}/revise")->assertNotFound();
    $this->getJson("/api/v1/quotes/{$theirs->id}/versions")->assertNotFound();

    quotverSwitch($b);
    expect(Quote::query()->where('tenant_id', $b->id)->count())->toBe(1);
});

it('quotver lists versions newest first and the list shows only the latest version', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotverUser($tenant);
    $v1 = quotverSeed($manager, 'Original');
    Sanctum::actingAs($manager);

    $v2 = $this->postJson("/api/v1/quotes/{$v1->id}/revise")->assertCreated()->json('id');

    foreach ([$v1->id, $v2] as $id) {
        $response = $this->getJson("/api/v1/quotes/{$id}/versions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.tenant_id')
            ->assertJsonMissingPath('data.0.lines');
        expect(collect($response->json('data'))->pluck('version')->all())->toBe([2, 1]);
        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$v2, $v1->id]);
    }

    // The list collapses to the latest version of each quote.
    $this->getJson('/api/v1/quotes')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $v2)
        ->assertJsonPath('data.0.version', 2);

    // The new draft version is editable; the old one is history.
    $this->putJson("/api/v1/quotes/{$v2}", [
        'title' => 'Revised',
        'currency' => 'USD',
        'lines' => [['name' => 'A', 'quantity' => 1, 'unit_price' => '5.00', 'tax_category' => 'zero']],
    ])->assertOk()->assertJsonPath('title', 'Revised')->assertJsonPath('version', 2)->assertJsonPath('total', '5.00');
    $this->putJson("/api/v1/quotes/{$v1->id}", [
        'title' => 'Nope',
        'currency' => 'USD',
        'lines' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('status');

    // Revising does not disturb numbering of the next new quote.
    $this->postJson('/api/v1/quotes', [
        'title' => 'Next',
        'currency' => 'USD',
        'lines' => [['name' => 'x', 'quantity' => 1, 'unit_price' => '1.00', 'tax_category' => 'zero']],
    ])->assertCreated()->assertJsonPath('quote_number', 'Q-00002')->assertJsonPath('version', 1);
});
