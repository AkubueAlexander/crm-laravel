<?php

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function invapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function invapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    invapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A one-line quote (zero tax, so total = price) pushed straight into the given status. */
function invapiQuote(User $actor, string $title = 'Website build', string $price = '100.00', string $status = 'accepted'): int
{
    invapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $id = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => $title,
        'currency' => 'USD',
        'lines' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => $price, 'tax_category' => 'zero']],
    ])->id;

    DB::table('quotes')->where('id', $id)->update(['status' => $status]);

    return $id;
}

function invapiInvoice(User $actor, string $title = 'Website build', string $price = '100.00', ?int $terms = null): int
{
    $quoteId = invapiQuote($actor, $title, $price);
    invapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    return app(CreateInvoiceFromQuoteAction::class)->execute($actor, $quoteId, $terms)->id;
}

it('invapi lists only the tenants invoices with filters, search and sorting', function () {
    $b = Tenant::factory()->create();
    invapiInvoice(invapiUser($b), 'Other tenant');

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $noRole = invapiUser($a, null);
    $i1 = invapiInvoice($manager, 'Alpha', '100.00');
    $i2 = invapiInvoice($manager, 'Beta', '300.00');
    $i3 = invapiInvoice($manager, 'Gamma', '200.00');
    DB::table('invoices')->where('id', $i2)->update(['status' => 'issued']);

    Sanctum::actingAs($manager);

    $this->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.invoice_number', 'INV-00003') // newest first by default
        ->assertJsonPath('data.0.lines_count', 1)
        ->assertJsonMissingPath('data.0.tenant_id');

    $this->getJson('/api/v1/invoices?status=issued')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $i2);
    $this->getJson('/api/v1/invoices?q=alp')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $i1);
    $this->getJson('/api/v1/invoices?sort=total&direction=asc')->assertOk()->assertJsonPath('data.0.total', '100.00');
    $this->getJson('/api/v1/invoices?sort=total&direction=desc')->assertOk()->assertJsonPath('data.0.total', '300.00');
    $this->getJson('/api/v1/invoices?sort=bogus')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/invoices?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');

    Sanctum::actingAs($noRole);
    $this->getJson('/api/v1/invoices')->assertForbidden();
});

it('invapi shows one invoice with its lines and never another tenants', function () {
    $b = Tenant::factory()->create();
    $theirs = invapiInvoice(invapiUser($b));

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $viewer = invapiUser($a, 'viewer');
    $mine = invapiInvoice($manager, 'Mine', '250.00');

    Sanctum::actingAs($viewer); // view-only is enough to read
    $this->getJson("/api/v1/invoices/{$mine}")
        ->assertOk()
        ->assertJsonPath('id', $mine)
        ->assertJsonPath('title', 'Mine')
        ->assertJsonPath('total', '250.00')
        ->assertJsonPath('status', 'draft')
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.name', 'Item')
        ->assertJsonMissingPath('tenant_id');

    $this->getJson("/api/v1/invoices/{$theirs}")->assertNotFound();
    $this->getJson('/api/v1/invoices/abc')->assertNotFound();
});

it('invapi creates a draft invoice from an accepted quote', function () {
    $b = Tenant::factory()->create();
    $theirsQuote = invapiQuote(invapiUser($b));

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $sales = invapiUser($a, 'sales');
    $viewer = invapiUser($a, 'viewer');
    $quote = invapiQuote($manager);
    $draftQuote = invapiQuote($manager, 'Not yet', '10.00', 'draft');

    Sanctum::actingAs($sales);
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote])->assertForbidden(); // sales may view, not manage
    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote])->assertForbidden();

    Sanctum::actingAs($manager);
    $this->postJson('/api/v1/invoices', [])->assertStatus(422)->assertJsonValidationErrors('quote_id');
    $this->postJson('/api/v1/invoices', ['quote_id' => $quote, 'payment_terms_days' => 400])->assertStatus(422)->assertJsonValidationErrors('payment_terms_days');
    $this->postJson('/api/v1/invoices', ['quote_id' => $draftQuote])->assertStatus(422)->assertJsonValidationErrors('quote_id');
    $this->postJson('/api/v1/invoices', ['quote_id' => $theirsQuote])->assertNotFound();

    $this->postJson('/api/v1/invoices', ['quote_id' => $quote, 'payment_terms_days' => 45])
        ->assertCreated()
        ->assertJsonPath('invoice_number', 'INV-00001')
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('lock_version', 0)
        ->assertJsonPath('payment_terms_days', 45)
        ->assertJsonPath('total', '100.00')
        ->assertJsonPath('issue_date', null)
        ->assertJsonCount(1, 'lines')
        ->assertJsonMissingPath('tenant_id');

    $this->postJson('/api/v1/invoices', ['quote_id' => $quote])->assertStatus(422)->assertJsonValidationErrors('quote_id'); // already invoiced
});

it('invapi walks the transition endpoint with 409, 422 and permission checks', function () {
    $b = Tenant::factory()->create();
    $theirs = invapiInvoice(invapiUser($b));

    $a = Tenant::factory()->create();
    $manager = invapiUser($a);
    $sales = invapiUser($a, 'sales');
    $id = invapiInvoice($manager, 'Deal', '100.00', 15);
    $go = fn (int $inv, string $to, int $lock, array $extra = []) => $this->postJson("/api/v1/invoices/{$inv}/transition", array_merge(['to_status' => $to, 'lock_version' => $lock], $extra));

    Sanctum::actingAs($sales);
    $go($id, 'issued', 0)->assertForbidden();

    Sanctum::actingAs($manager);
    $go($theirs, 'issued', 0)->assertNotFound();
    $go($id, 'bogus', 0)->assertStatus(422)->assertJsonValidationErrors('to_status');
    $go($id, 'paid', 0)->assertStatus(422)->assertJsonPath('code', 'invalid_transition'); // draft cannot be paid

    $go($id, 'issued', 9)
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_invoice')
        ->assertJsonPath('current.id', $id)
        ->assertJsonPath('current.status', 'draft')
        ->assertJsonPath('current.lock_version', 0);

    $go($id, 'issued', 0)
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('lock_version', 1)
        ->assertJsonPath('issue_date', now()->toDateString())
        ->assertJsonPath('due_date', now()->addDays(15)->toDateString())
        ->assertJsonMissingPath('tenant_id');

    $go($id, 'void', 1)->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go($id, 'void', 1, ['note' => '  '])->assertStatus(422)->assertJsonPath('code', 'note_required');
    $go($id, 'paid', 1)->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('lock_version', 2);
    $go($id, 'void', 2, ['note' => 'too late'])->assertStatus(422)->assertJsonPath('code', 'invalid_transition'); // paid is terminal

    $voidMe = invapiInvoice($manager, 'Cancelled', '50.00');
    $go($voidMe, 'void', 0, ['note' => 'Customer cancelled'])
        ->assertOk()
        ->assertJsonPath('status', 'void')
        ->assertJsonPath('void_reason', 'Customer cancelled');

    expect(DB::table('invoices')->where('id', $theirs)->value('status'))->toBe('draft');
});
