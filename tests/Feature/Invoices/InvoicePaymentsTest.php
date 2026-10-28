<?php

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Domain\Invoices\Actions\RecordPaymentAction;
use App\Domain\Invoices\Actions\TransitionInvoiceAction;
use App\Domain\Invoices\Actions\VoidPaymentAction;
use App\Domain\Invoices\Exceptions\InvoiceTransitionException;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function invpaySwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function invpayUser(Tenant $tenant, string $role = 'manager'): User
{
    invpaySwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A one-line quote (zero tax, so total = price) turned into an invoice; issued unless $issue is false. Returns the invoice id (lock_version 1 when issued). */
function invpayInvoice(User $actor, string $price = '100.00', bool $issue = true): int
{
    invpaySwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $quoteId = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Website build',
        'currency' => 'USD',
        'lines' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => $price, 'tax_category' => 'zero']],
    ])->id;
    DB::table('quotes')->where('id', $quoteId)->update(['status' => 'accepted']);

    $id = app(CreateInvoiceFromQuoteAction::class)->execute($actor, $quoteId)->id;

    if ($issue) {
        app(TransitionInvoiceAction::class)->execute($actor, $id, 'issued', 0);
    }

    return $id;
}

function invpayCatch(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('invpay takes partial payments and settles the invoice when the balance reaches zero', function () {
    $tenant = Tenant::factory()->create();
    $manager = invpayUser($tenant);
    $id = invpayInvoice($manager, '100.00');
    $record = app(RecordPaymentAction::class);

    $first = $record->execute($manager, $id, '40.00', 'bank_transfer', null, 'TRX-1', 'Deposit');
    expect($first->status)->toBe('issued')
        ->and($first->amount_paid)->toBe('40.00')
        ->and($first->lock_version)->toBe(2)
        ->and($first->paid_at)->toBeNull()
        ->and($first->payments)->toHaveCount(1)
        ->and($first->payments[0]->method)->toBe('bank_transfer')
        ->and($first->payments[0]->reference)->toBe('TRX-1')
        ->and($first->payments[0]->recorded_by)->toBe($manager->id)
        ->and($first->payments[0]->paid_on->toDateString())->toBe(now()->toDateString());

    $second = $record->execute($manager, $id, '60.00', 'card');
    expect($second->status)->toBe('paid')
        ->and($second->amount_paid)->toBe('100.00')
        ->and($second->lock_version)->toBe(3)
        ->and($second->paid_at)->not->toBeNull()
        ->and($second->paid_by)->toBe($manager->id);

    expect(DB::table('payments')->where('invoice_id', $id)->orderBy('id')->pluck('amount')->all())->toBe(['40.00', '60.00']);
});

it('invpay refuses bad payments, overpayment, wrong status and unauthorized actors', function () {
    $b = Tenant::factory()->create();
    $theirs = invpayInvoice(invpayUser($b));

    $a = Tenant::factory()->create();
    $manager = invpayUser($a);
    $sales = invpayUser($a, 'sales');
    $id = invpayInvoice($manager, '100.00');
    $draft = invpayInvoice($manager, '50.00', false);
    $record = app(RecordPaymentAction::class);

    expect(fn () => $record->execute($manager, $draft, '10.00', 'cash'))->toThrow(ValidationException::class, 'only be recorded on issued invoices');
    expect(fn () => $record->execute($manager, $id, '100.01', 'cash'))->toThrow(ValidationException::class, 'exceeds the outstanding balance of 100.00');
    expect(fn () => $record->execute($manager, $id, '0.00', 'cash'))->toThrow(ValidationException::class, 'greater than zero');
    expect(fn () => $record->execute($manager, $id, '-5', 'cash'))->toThrow(ValidationException::class, 'valid amount');
    expect(fn () => $record->execute($manager, $id, '10.555', 'cash'))->toThrow(ValidationException::class, 'valid amount');
    expect(fn () => $record->execute($manager, $id, '10.00', 'bitcoin'))->toThrow(ValidationException::class, 'Unknown payment method');
    expect(fn () => $record->execute($manager, $id, '10.00', 'cash', now()->addDay()->toDateString()))->toThrow(ValidationException::class, 'cannot be in the future');
    expect(fn () => $record->execute($manager, $id, '10.00', 'cash', '2026-13-45'))->toThrow(ValidationException::class, 'valid date');
    expect(fn () => $record->execute($sales, $id, '10.00', 'cash'))->toThrow(AuthorizationException::class); // sales may view, not manage

    invpaySwitch($a);
    expect(fn () => $record->execute($manager, $theirs, '10.00', 'cash'))->toThrow(ModelNotFoundException::class);

    expect(DB::table('payments')->count())->toBe(0);
});

it('invpay reverses a payment, reopens a paid invoice and keeps the row on record', function () {
    $tenant = Tenant::factory()->create();
    $manager = invpayUser($tenant);
    $id = invpayInvoice($manager, '100.00');
    $record = app(RecordPaymentAction::class);
    $void = app(VoidPaymentAction::class);

    $record->execute($manager, $id, '30.00', 'cash');
    $paid = $record->execute($manager, $id, '70.00', 'cheque');
    expect($paid->status)->toBe('paid');

    $seventy = (int) DB::table('payments')->where('invoice_id', $id)->where('amount', '70.00')->value('id');

    expect(fn () => $void->execute($manager, $seventy, '  '))->toThrow(ValidationException::class, 'Give a reason');

    $reopened = $void->execute($manager, $seventy, 'Cheque bounced');
    expect($reopened->status)->toBe('issued')
        ->and($reopened->amount_paid)->toBe('30.00')
        ->and($reopened->paid_at)->toBeNull()
        ->and($reopened->lock_version)->toBe(4); // issue 1, pay 2, pay 3, reverse 4

    $row = DB::table('payments')->where('id', $seventy)->first();
    expect($row->voided_at)->not->toBeNull()->and($row->voided_by)->toBe($manager->id)->and($row->void_reason)->toBe('Cheque bounced');

    expect(fn () => $void->execute($manager, $seventy, 'again'))->toThrow(ValidationException::class, 'already reversed');

    // The reversed amount can be collected again.
    $again = $record->execute($manager, $id, '70.00', 'bank_transfer');
    expect($again->status)->toBe('paid')->and($again->amount_paid)->toBe('100.00');
});

it('invpay settles the balance on mark-as-paid and refuses to void an invoice with payments', function () {
    $tenant = Tenant::factory()->create();
    $manager = invpayUser($tenant);
    $transition = app(TransitionInvoiceAction::class);

    $id = invpayInvoice($manager, '100.00');
    app(RecordPaymentAction::class)->execute($manager, $id, '30.00', 'cash');

    $paid = $transition->execute($manager, $id, 'paid', 2);
    expect($paid->status)->toBe('paid')->and($paid->amount_paid)->toBe('100.00')->and($paid->lock_version)->toBe(3);

    $rows = DB::table('payments')->where('invoice_id', $id)->orderBy('id')->get();
    expect($rows->pluck('amount')->all())->toBe(['30.00', '70.00'])
        ->and($rows[1]->method)->toBe('other')
        ->and($rows[1]->note)->toBe('Marked as paid');

    // A partly paid invoice cannot be voided; reverse the payment first.
    $other = invpayInvoice($manager, '100.00');
    app(RecordPaymentAction::class)->execute($manager, $other, '10.00', 'cash');
    $e = invpayCatch(fn () => $transition->execute($manager, $other, 'void', 2, 'cancel'));
    expect($e)->toBeInstanceOf(InvoiceTransitionException::class)->and($e->reason)->toBe('has_payments');

    // An untouched issued invoice can still be marked paid with a single full payment.
    $plain = invpayInvoice($manager, '80.00');
    $transition->execute($manager, $plain, 'paid', 1);
    expect(DB::table('payments')->where('invoice_id', $plain)->pluck('amount')->all())->toBe(['80.00']);
});

it('invpay exposes amount_paid and balance on the invoice resource', function () {
    $tenant = Tenant::factory()->create();
    $manager = invpayUser($tenant);
    $id = invpayInvoice($manager, '100.00');
    app(RecordPaymentAction::class)->execute($manager, $id, '40.00', 'cash');

    Sanctum::actingAs($manager);
    $this->getJson("/api/v1/invoices/{$id}")
        ->assertOk()
        ->assertJsonPath('total', '100.00')
        ->assertJsonPath('amount_paid', '40.00')
        ->assertJsonPath('balance', '60.00');
});
