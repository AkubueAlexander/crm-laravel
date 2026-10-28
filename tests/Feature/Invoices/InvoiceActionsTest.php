<?php

use App\Domain\Invoices\Actions\CreateInvoiceFromQuoteAction;
use App\Domain\Invoices\Actions\TransitionInvoiceAction;
use App\Domain\Invoices\Exceptions\InvoiceTransitionException;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function invactSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function invactUser(Tenant $tenant, string $role = 'manager'): User
{
    invactSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A quote pushed straight into the given status. Default lines: A (standard tax) + B (zero tax) = 70.00 + 1.50 = 71.50. */
function invactQuote(User $actor, string $status = 'accepted'): int
{
    invactSwitch(Tenant::query()->findOrFail($actor->tenant_id));

    $id = app(SaveQuoteAction::class)->execute($actor, null, [
        'title' => 'Website build',
        'currency' => 'USD',
        'notes' => 'Thanks',
        'lines' => [
            ['name' => 'A', 'quantity' => 2, 'unit_price' => '10.00', 'tax_category' => 'standard'],
            ['name' => 'B', 'quantity' => 1, 'unit_price' => '50.00', 'tax_category' => 'zero'],
        ],
    ])->id;

    DB::table('quotes')->where('id', $id)->update(['status' => $status]);

    return $id;
}

function invactCatch(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('invact snapshots an accepted quote into a draft invoice', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $quoteId = invactQuote($manager);
    $action = app(CreateInvoiceFromQuoteAction::class);

    $invoice = $action->execute($manager, $quoteId);

    expect($invoice->invoice_number)->toBe('INV-00001')
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->lock_version)->toBe(0)
        ->and($invoice->title)->toBe('Website build')
        ->and($invoice->quote_id)->toBe($quoteId)
        ->and($invoice->payment_terms_days)->toBe(30)
        ->and($invoice->issue_date)->toBeNull()
        ->and($invoice->subtotal)->toBe('70.00')
        ->and($invoice->tax_total)->toBe('1.50')
        ->and($invoice->total)->toBe('71.50')
        ->and($invoice->created_by)->toBe($manager->id)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines[0]->name)->toBe('A')
        ->and($invoice->lines[0]->quantity)->toBe('2.000')
        ->and($invoice->lines[0]->line_total)->toBe('20.00')
        ->and($invoice->lines[0]->tax_amount)->toBe('1.50');

    $second = $action->execute($manager, invactQuote($manager));
    expect($second->invoice_number)->toBe('INV-00002');
});

it('invact refuses a non-accepted quote, a duplicate and bad terms, but allows re-invoicing after a void', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $action = app(CreateInvoiceFromQuoteAction::class);

    $draftQuote = invactQuote($manager, 'draft');
    expect(fn () => $action->execute($manager, $draftQuote))->toThrow(ValidationException::class, 'Only accepted quotes can be invoiced.');

    $quoteId = invactQuote($manager);
    expect(fn () => $action->execute($manager, $quoteId, 400))->toThrow(ValidationException::class, 'between 0 and 365');

    $first = $action->execute($manager, $quoteId, 15);
    expect($first->payment_terms_days)->toBe(15);
    expect(fn () => $action->execute($manager, $quoteId))->toThrow(ValidationException::class, 'already has an invoice');

    DB::table('invoices')->where('id', $first->id)->update(['status' => 'void']);
    $again = $action->execute($manager, $quoteId);
    expect($again->invoice_number)->toBe('INV-00002')->and($again->status)->toBe('draft');
});

it('invact enforces permissions and tenant isolation', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $theirs = invactQuote(invactUser($b));
    $managerA = invactUser($a);
    $viewerA = invactUser($a, 'viewer');
    $salesA = invactUser($a, 'sales');
    $mine = invactQuote($managerA);
    $action = app(CreateInvoiceFromQuoteAction::class);

    expect(fn () => $action->execute($viewerA, $mine))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($salesA, $mine))->toThrow(AuthorizationException::class); // sales may view, not manage

    invactSwitch($a);
    expect(fn () => $action->execute($managerA, $theirs))->toThrow(ModelNotFoundException::class);

    invactSwitch($b);
    expect(DB::table('invoices')->count())->toBe(0);
});

it('invact walks an invoice from draft to paid and stamps the dates', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $invoice = app(CreateInvoiceFromQuoteAction::class)->execute($manager, invactQuote($manager), 15);
    $transition = app(TransitionInvoiceAction::class);

    $issued = $transition->execute($manager, $invoice->id, 'issued', 0);
    expect($issued->status)->toBe('issued')
        ->and($issued->lock_version)->toBe(1)
        ->and($issued->issued_by)->toBe($manager->id)
        ->and($issued->issue_date->toDateString())->toBe(now()->toDateString())
        ->and($issued->due_date->toDateString())->toBe(now()->addDays(15)->toDateString());

    $paid = $transition->execute($manager, $invoice->id, 'paid', 1);
    expect($paid->status)->toBe('paid')->and($paid->lock_version)->toBe(2)->and($paid->paid_at)->not->toBeNull();

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'void', 2, 'oops'));
    expect($e)->toBeInstanceOf(InvoiceTransitionException::class)->and($e->reason)->toBe('invalid_transition'); // paid is terminal
});

it('invact answers a stale lock_version with the current invoice', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $invoice = app(CreateInvoiceFromQuoteAction::class)->execute($manager, invactQuote($manager));

    $e = invactCatch(fn () => app(TransitionInvoiceAction::class)->execute($manager, $invoice->id, 'issued', 5));

    expect($e)->toBeInstanceOf(InvoiceTransitionException::class)
        ->and($e->isStale())->toBeTrue()
        ->and($e->invoice->status)->toBe('draft')
        ->and($e->invoice->lock_version)->toBe(0);
    expect(Invoice::query()->where('tenant_id', $tenant->id)->value('status'))->toBe('draft');
});

it('invact voids only with a reason and refuses illegal moves and unauthorized actors', function () {
    $tenant = Tenant::factory()->create();
    $manager = invactUser($tenant);
    $sales = invactUser($tenant, 'sales');
    $invoice = app(CreateInvoiceFromQuoteAction::class)->execute($manager, invactQuote($manager));
    $transition = app(TransitionInvoiceAction::class);

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'paid', 0)); // draft cannot be paid
    expect($e->reason)->toBe('invalid_transition');

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'void', 0, '   '));
    expect($e->reason)->toBe('note_required');

    expect(fn () => $transition->execute($manager, $invoice->id, 'bogus', 0))->toThrow(ValidationException::class);
    expect(fn () => $transition->execute($sales, $invoice->id, 'issued', 0))->toThrow(AuthorizationException::class);

    $voided = $transition->execute($manager, $invoice->id, 'void', 0, 'Customer cancelled');
    expect($voided->status)->toBe('void')
        ->and($voided->lock_version)->toBe(1)
        ->and($voided->void_reason)->toBe('Customer cancelled')
        ->and($voided->voided_at)->not->toBeNull();

    $e = invactCatch(fn () => $transition->execute($manager, $invoice->id, 'issued', 1));
    expect($e->reason)->toBe('invalid_transition'); // void is terminal
});
