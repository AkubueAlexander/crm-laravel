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

function paymapiSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function paymapiUser(Tenant $tenant, ?string $role = 'manager'): User
{
    paymapiSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A one-line zero-tax invoice (total = price) from an accepted quote; issued unless told otherwise. */
function paymapiInvoice(User $actor, string $price = '100.00', bool $issue = true): int
{
    paymapiSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $quoteId = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Payments test',
        'currency' => 'USD',
        'lines' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => $price, 'tax_category' => 'zero']],
    ])->id;
    DB::table('quotes')->where('id', $quoteId)->update(['status' => 'accepted']);

    $id = app(CreateInvoiceFromQuoteAction::class)->execute($actor, $quoteId, null)->id;

    if ($issue) {
        DB::table('invoices')->where('id', $id)->update(['status' => 'issued']);
    }

    return $id;
}

it('paymapi records partial and full payments and exposes them on the invoice', function () {
    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $id = paymapiInvoice($manager);
    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '40.00', 'method' => 'cash', 'reference' => 'R-1'])
        ->assertCreated()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('amount_paid', '40.00')
        ->assertJsonPath('balance', '60.00')
        ->assertJsonPath('lock_version', 1)
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.method', 'cash')
        ->assertJsonPath('payments.0.amount', '40.00')
        ->assertJsonPath('payments.0.reference', 'R-1')
        ->assertJsonPath('payments.0.paid_on', now()->toDateString())
        ->assertJsonMissingPath('payments.0.tenant_id')
        ->assertJsonMissingPath('payments.0.invoice_id');

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '60.01', 'method' => 'card'])
        ->assertStatus(422)->assertJsonValidationErrors('amount');

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => 60, 'method' => 'bank_transfer'])
        ->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('amount_paid', '100.00')
        ->assertJsonPath('balance', '0.00')
        ->assertJsonPath('lock_version', 2)
        ->assertJsonCount(2, 'payments');

    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '1.00', 'method' => 'cash'])
        ->assertStatus(422)->assertJsonValidationErrors('invoice');

    $this->getJson("/api/v1/invoices/{$id}")
        ->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonCount(2, 'payments')
        ->assertJsonPath('payments.1.method', 'bank_transfer');

    $this->get("/api/v1/invoices/{$id}/pdf")->assertOk(); // PDF still renders with the paid/balance lines
});

it('paymapi validates the payment body and the invoice status', function () {
    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $id = paymapiInvoice($manager);
    $draft = paymapiInvoice($manager, '50.00', false);
    Sanctum::actingAs($manager);
    $url = "/api/v1/invoices/{$id}/payments";

    $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['amount', 'method']);
    $this->postJson($url, ['amount' => 'abc', 'method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson($url, ['amount' => '0', 'method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson($url, ['amount' => '1.234', 'method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('amount');
    $this->postJson($url, ['amount' => '10', 'method' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('method');
    $this->postJson($url, ['amount' => '10', 'method' => 'cash', 'paid_on' => '2026-13-45'])->assertStatus(422)->assertJsonValidationErrors('paid_on');
    $this->postJson($url, ['amount' => '10', 'method' => 'cash', 'paid_on' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_on');

    $this->postJson("/api/v1/invoices/{$draft}/payments", ['amount' => '10', 'method' => 'cash'])
        ->assertStatus(422)->assertJsonValidationErrors('invoice'); // draft invoices take no payments

    expect(DB::table('payments')->count())->toBe(0);
});

it('paymapi refuses sales and viewers and never reaches another tenant', function () {
    $b = Tenant::factory()->create();
    $otherManager = paymapiUser($b);

    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $sales = paymapiUser($a, 'sales');
    $viewer = paymapiUser($a, 'viewer');
    $id = paymapiInvoice($manager);

    Sanctum::actingAs($manager);
    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '10', 'method' => 'cash'])->assertCreated();
    $paymentId = (int) DB::table('payments')->where('invoice_id', $id)->value('id');

    foreach ([$sales, $viewer] as $user) {
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '10', 'method' => 'cash'])->assertForbidden();
        $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'nope'])->assertForbidden();
    }

    Sanctum::actingAs($otherManager);
    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '10', 'method' => 'cash'])->assertNotFound();
    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'nope'])->assertNotFound();

    expect(DB::table('payments')->where('invoice_id', $id)->whereNull('voided_at')->count())->toBe(1);
});

it('paymapi reverses a payment, reopens a paid invoice and refuses a double reversal', function () {
    $a = Tenant::factory()->create();
    $manager = paymapiUser($a);
    $id = paymapiInvoice($manager);
    Sanctum::actingAs($manager);

    $paid = $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '100.00', 'method' => 'cheque'])
        ->assertCreated()
        ->assertJsonPath('status', 'paid');
    $paymentId = (int) $paid->json('payments.0.id');

    $this->postJson("/api/v1/payments/{$paymentId}/void", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => '   '])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/payments/99999999/void', ['reason' => 'x'])->assertNotFound();

    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'Cheque bounced'])
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('amount_paid', '0.00')
        ->assertJsonPath('balance', '100.00')
        ->assertJsonPath('lock_version', 2)
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.void_reason', 'Cheque bounced');

    $this->postJson("/api/v1/payments/{$paymentId}/void", ['reason' => 'again'])
        ->assertStatus(422)->assertJsonValidationErrors('payment');

    $this->getJson("/api/v1/invoices/{$id}")
        ->assertOk()
        ->assertJsonCount(1, 'payments')
        ->assertJsonPath('payments.0.void_reason', 'Cheque bounced');

    expect(DB::table('payments')->where('id', $paymentId)->value('voided_at'))->not->toBeNull();

    // The reopened invoice takes a fresh payment again.
    $this->postJson("/api/v1/invoices/{$id}/payments", ['amount' => '25.00', 'method' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('amount_paid', '25.00')
        ->assertJsonCount(2, 'payments');
});
