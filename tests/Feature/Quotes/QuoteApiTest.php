<?php

use App\Domain\Products\Actions\SaveProductAction;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function quoteapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function quoteapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    quoteapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function quoteapiProduct(User $actor, string $sku, string $price = '100.00'): Product
{
    quoteapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(SaveProductAction::class)->execute($actor, null, [
        'sku' => $sku,
        'name' => 'Item '.$sku,
        'unit_price' => $price,
        'tax_category' => 'standard',
        'is_active' => true,
        'variants' => [],
    ]);
}

/** @param array<string, mixed> $over */
function quoteapiLine(array $over = []): array
{
    return array_merge(['name' => 'Setup', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero'], $over);
}

/** @param array<string, mixed> $over */
function quoteapiBody(array $over = []): array
{
    return array_merge(['title' => 'Quote A', 'currency' => 'USD', 'lines' => [quoteapiLine()]], $over);
}

/** @param array<string, mixed> $over */
function quoteapiSeed(User $actor, string $title, string $price = '10.00', array $over = []): Quote
{
    quoteapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(SaveQuoteAction::class)->execute($actor, null, array_merge([
        'title' => $title,
        'currency' => 'USD',
        'lines' => [quoteapiLine(['unit_price' => $price])],
    ], $over));
}

it('quoteapi forbids access without the quote permissions', function () {
    $tenant = Tenant::factory()->create();
    $none = quoteapiUser($tenant, null);
    $viewer = quoteapiUser($tenant, 'viewer');
    $quote = quoteapiSeed(quoteapiUser($tenant), 'One');

    Sanctum::actingAs($none);
    $this->getJson('/api/v1/quotes')->assertForbidden();
    $this->getJson("/api/v1/quotes/{$quote->id}")->assertForbidden();

    // Viewers can read, not write.
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/quotes')->assertOk();
    $this->getJson("/api/v1/quotes/{$quote->id}")->assertOk();
    $this->postJson('/api/v1/quotes', quoteapiBody())->assertForbidden();
    $this->putJson("/api/v1/quotes/{$quote->id}", quoteapiBody())->assertForbidden();
    $this->deleteJson("/api/v1/quotes/{$quote->id}")->assertForbidden();
});

it('quoteapi lists only the actors tenant with the whitelisted shape', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = quoteapiUser($a);
    $managerB = quoteapiUser($b);
    quoteapiSeed($managerA, 'Alpha');
    quoteapiSeed($managerB, 'Bravo');

    Sanctum::actingAs($managerA);
    $response = $this->getJson('/api/v1/quotes')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => [
                'id', 'quote_seq', 'quote_number', 'version', 'status', 'lock_version', 'title', 'account_id', 'contact_id',
                'currency', 'valid_until', 'notes', 'terms', 'subtotal', 'tax_total', 'total', 'lines_count', 'created_at', 'updated_at',
            ]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ])
        ->assertJsonPath('meta.total', 1)
        ->assertJsonMissingPath('data.0.tenant_id')
        ->assertJsonMissingPath('data.0.lines');

    expect($response->json('data.0.title'))->toBe('Alpha');
    expect($response->json('data.0.quote_number'))->toBe('Q-00001');
    expect($response->json('data.0.lines_count'))->toBe(1);
});

it('quoteapi searches, filters and sorts', function () {
    $tenant = Tenant::factory()->create();
    $manager = quoteapiUser($tenant);
    quoteapiSeed($manager, 'Alpha', '30');
    $beta = quoteapiSeed($manager, 'Beta', '10');
    quoteapiSeed($manager, 'Gamma', '20');
    DB::table('quotes')->where('id', $beta->id)->update(['status' => 'sent']);

    Sanctum::actingAs($manager);
    $titles = fn (string $query) => collect($this->getJson("/api/v1/quotes?{$query}")->assertOk()->json('data'))->pluck('title')->all();

    expect($titles(''))->toBe(['Gamma', 'Beta', 'Alpha']); // default: newest number first
    expect($titles('sort=quote_number&direction=asc'))->toBe(['Alpha', 'Beta', 'Gamma']);
    expect($titles('q=alp'))->toBe(['Alpha']);
    expect($titles('q=Q-00002'))->toBe(['Beta']); // matches the number
    expect($titles('q=%25'))->toBe([]); // a literal percent sign, not a wildcard
    expect($titles('status=sent'))->toBe(['Beta']);
    expect($titles('status=draft'))->toBe(['Gamma', 'Alpha']);
    expect($titles('sort=total&direction=desc'))->toBe(['Alpha', 'Gamma', 'Beta']);

    $this->getJson('/api/v1/quotes?sort=tenant_id')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/quotes?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
});

it('quoteapi creates a quote with server-computed totals', function () {
    $tenant = Tenant::factory()->create();
    $manager = quoteapiUser($tenant);
    $product = quoteapiProduct($manager, 'WID', '100.00');
    Sanctum::actingAs($manager);

    $this->postJson('/api/v1/quotes', quoteapiBody([
        'total' => '1.00', // ignored: totals are never accepted from the client
        'status' => 'accepted', // ignored
        'lines' => [
            ['product_id' => $product->id, 'quantity' => 2],
            quoteapiLine(),
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('quote_number', 'Q-00001')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('subtotal', '250.00')
        ->assertJsonPath('tax_total', '15.00')
        ->assertJsonPath('total', '265.00')
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('lines.0.sku', 'WID')
        ->assertJsonPath('lines.0.product_id', $product->id)
        ->assertJsonPath('lines.1.name', 'Setup')
        ->assertJsonMissingPath('tenant_id');

    quoteapiSwitch($tenant);
    expect(Quote::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('quoteapi validates input and maps action errors to 422', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = quoteapiUser($a);
    $managerB = quoteapiUser($b);
    $theirProduct = quoteapiProduct($managerB, 'THEIRS');
    quoteapiSwitch($b);
    $theirContact = Contact::factory()->create(['tenant_id' => $b->id]);

    Sanctum::actingAs($managerA);

    $this->postJson('/api/v1/quotes', [])->assertStatus(422)->assertJsonValidationErrors(['title', 'lines']);
    $this->postJson('/api/v1/quotes', quoteapiBody(['currency' => 'usd']))->assertStatus(422)->assertJsonValidationErrors('currency');
    $this->postJson('/api/v1/quotes', quoteapiBody(['valid_until' => '31/12/2026']))->assertStatus(422)->assertJsonValidationErrors('valid_until');
    $this->postJson('/api/v1/quotes', quoteapiBody(['lines' => [quoteapiLine(['quantity' => 0])]]))->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
    $this->postJson('/api/v1/quotes', quoteapiBody(['lines' => [quoteapiLine(['discount_percent' => 101])]]))->assertStatus(422)->assertJsonValidationErrors('lines.0.discount_percent');
    $this->postJson('/api/v1/quotes', quoteapiBody(['lines' => [quoteapiLine(['tax_category' => 'luxury'])]]))->assertStatus(422)->assertJsonValidationErrors('lines.0.tax_category');

    // Custom line without a price: caught by the Action.
    $this->postJson('/api/v1/quotes', quoteapiBody(['lines' => [['name' => 'No price', 'quantity' => 1, 'tax_category' => 'zero']]]))
        ->assertStatus(422)->assertJsonValidationErrors('lines.0.unit_price');

    // Another tenant's contact / product must look like they do not exist.
    $this->postJson('/api/v1/quotes', quoteapiBody(['contact_id' => $theirContact->id]))->assertStatus(422)->assertJsonValidationErrors('contact_id');
    $this->postJson('/api/v1/quotes', quoteapiBody(['lines' => [['product_id' => $theirProduct->id, 'quantity' => 1]]]))
        ->assertStatus(422)->assertJsonValidationErrors('lines.0.product_id');

    quoteapiSwitch($a);
    expect(Quote::query()->where('tenant_id', $a->id)->count())->toBe(0);
});

it('quoteapi shows and updates a draft, replacing its lines', function () {
    $tenant = Tenant::factory()->create();
    $manager = quoteapiUser($tenant);
    $quote = quoteapiSeed($manager, 'One', '10.00');
    Sanctum::actingAs($manager);

    $this->getJson("/api/v1/quotes/{$quote->id}")
        ->assertOk()
        ->assertJsonPath('title', 'One')
        ->assertJsonPath('lock_version', 0)
        ->assertJsonCount(1, 'lines');

    $this->putJson("/api/v1/quotes/{$quote->id}", quoteapiBody([
        'title' => 'Renamed',
        'lines' => [quoteapiLine(['quantity' => 3, 'unit_price' => '10.00'])],
    ]))
        ->assertOk()
        ->assertJsonPath('title', 'Renamed')
        ->assertJsonPath('quote_number', 'Q-00001')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('total', '30.00')
        ->assertJsonCount(1, 'lines');

    // `lines` must be present on update: omitting it must not wipe the lines.
    $body = quoteapiBody();
    unset($body['lines']);
    $this->putJson("/api/v1/quotes/{$quote->id}", $body)->assertStatus(422)->assertJsonValidationErrors('lines');

    // Once it left draft it is history.
    DB::table('quotes')->where('id', $quote->id)->update(['status' => 'sent']);
    $this->putJson("/api/v1/quotes/{$quote->id}", quoteapiBody())->assertStatus(422)->assertJsonValidationErrors('status');
});

it('quoteapi never reads or changes another tenants quote', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $managerA = quoteapiUser($a);
    $theirs = quoteapiSeed(quoteapiUser($b), 'Bravo');

    Sanctum::actingAs($managerA);
    $this->getJson("/api/v1/quotes/{$theirs->id}")->assertNotFound();
    $this->putJson("/api/v1/quotes/{$theirs->id}", quoteapiBody())->assertNotFound();
    $this->deleteJson("/api/v1/quotes/{$theirs->id}")->assertNotFound();

    quoteapiSwitch($b);
    expect(Quote::query()->where('tenant_id', $b->id)->count())->toBe(1);
});

it('quoteapi deletes a draft (204) but refuses once it left draft', function () {
    $tenant = Tenant::factory()->create();
    $manager = quoteapiUser($tenant);
    $draft = quoteapiSeed($manager, 'Draft');
    $sent = quoteapiSeed($manager, 'Sent');
    DB::table('quotes')->where('id', $sent->id)->update(['status' => 'sent']);
    Sanctum::actingAs($manager);

    $this->deleteJson("/api/v1/quotes/{$sent->id}")->assertStatus(422)->assertJsonValidationErrors('status');
    $this->deleteJson("/api/v1/quotes/{$draft->id}")->assertNoContent();
    $this->getJson("/api/v1/quotes/{$draft->id}")->assertNotFound();
    $this->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('meta.total', 1);
});
