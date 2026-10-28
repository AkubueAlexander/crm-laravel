<?php

use App\Domain\Products\Actions\SaveProductAction;
use App\Domain\Quotes\Actions\DeleteQuoteAction;
use App\Domain\Quotes\Actions\SaveQuoteAction;
use App\Domain\Quotes\QuoteTotals;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Contact;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function quotactSwitch(Tenant $tenant): void
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
}

function quotactActor(Tenant $tenant, string $role = 'sales'): User
{
    quotactSwitch($tenant);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** @param array<string, mixed> $over */
function quotactLine(array $over = []): array
{
    return array_merge(['name' => 'Consulting', 'quantity' => '2', 'unit_price' => '100.00', 'discount_percent' => '0', 'tax_category' => 'zero'], $over);
}

/** @param array<string, mixed> $over */
function quotactData(array $over = []): array
{
    return array_merge(['title' => 'Website build', 'currency' => 'USD', 'lines' => [quotactLine()]], $over);
}

it('quotact computes totals in cents with half-up rounding', function () {
    $r = QuoteTotals::compute([
        ['quantity' => 2, 'unit_cents' => 10000, 'discount_percent' => 10, 'tax_category' => 'standard'],
        ['quantity' => 3, 'unit_cents' => 33, 'discount_percent' => 0, 'tax_category' => 'standard'],
        ['quantity' => 1, 'unit_cents' => 5000, 'discount_percent' => 0, 'tax_category' => 'exempt'],
    ]);

    expect($r['lines'][0]['net_cents'])->toBe(18000);
    expect($r['lines'][0]['tax_cents'])->toBe(1350);
    expect($r['lines'][1]['net_cents'])->toBe(99);
    expect($r['lines'][1]['tax_cents'])->toBe(7);
    expect($r['lines'][2]['tax_cents'])->toBe(0);
    expect($r['subtotal_cents'])->toBe(23099);
    expect($r['tax_cents'])->toBe(1357);
    expect($r['total_cents'])->toBe(24456);
    expect(QuoteTotals::money(24456))->toBe('244.56');
});

it('quotact creates quotes with per-tenant sequential numbers and server-side totals', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorA = quotactActor($a);
    $actorB = quotactActor($b);
    $save = app(SaveQuoteAction::class);

    quotactSwitch($a);
    $first = $save->execute($actorA, null, quotactData(['lines' => [quotactLine(['discount_percent' => '10', 'tax_category' => 'standard'])]]));
    $second = $save->execute($actorA, null, quotactData(['title' => 'Second']));

    expect($first->quote_number)->toBe('Q-00001');
    expect($second->quote_number)->toBe('Q-00002');
    expect($first->version)->toBe(1);
    expect($first->status)->toBe('draft');
    expect((int) $first->tenant_id)->toBe($a->id);
    expect($first->subtotal)->toBe('180.00');
    expect($first->tax_total)->toBe('13.50');
    expect($first->total)->toBe('193.50');
    expect($first->lines)->toHaveCount(1);
    expect($first->lines[0]->tax_rate)->toBe('0.0750');

    quotactSwitch($b);
    expect($save->execute($actorB, null, quotactData())->quote_number)->toBe('Q-00001');
});

it('quotact snapshots catalog lines and honours a price override', function () {
    $tenant = Tenant::factory()->create();
    $manager = quotactActor($tenant, 'manager');

    $product = app(SaveProductAction::class)->execute($manager, null, [
        'sku' => 'LAP', 'name' => 'Laptop', 'unit_price' => '50.00', 'tax_category' => 'standard', 'is_active' => true,
        'variants' => [['sku' => 'LAP-16', 'name' => '16GB', 'unit_price' => '60.00'], ['sku' => 'LAP-8', 'name' => '8GB']],
    ]);
    [$v16, $v8] = [$product->variants[0], $product->variants[1]];

    $quote = app(SaveQuoteAction::class)->execute($manager, null, quotactData(['lines' => [
        ['product_id' => $product->id, 'variant_id' => $v16->id, 'quantity' => '1'],
        ['product_id' => $product->id, 'variant_id' => $v8->id, 'quantity' => '2'],
        ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '45.00'],
    ]]));

    expect($quote->lines[0]->name)->toBe('Laptop - 16GB');
    expect($quote->lines[0]->sku)->toBe('LAP-16');
    expect($quote->lines[0]->unit_price)->toBe('60.00');
    expect($quote->lines[1]->unit_price)->toBe('50.00'); // variant without a price inherits the product price
    expect($quote->lines[2]->unit_price)->toBe('45.00'); // explicit override
    expect($quote->lines[2]->tax_amount)->toBe('3.38');
    expect($quote->subtotal)->toBe('205.00');
    expect($quote->tax_total)->toBe('15.38');
    expect($quote->total)->toBe('220.38');

    // Later catalog edits never change the quote.
    DB::table('products')->where('id', $product->id)->update(['name' => 'Renamed', 'unit_price' => '999.00']);
    expect(QuoteLine::query()->where('quote_id', $quote->id)->orderBy('position')->first()->name)->toBe('Laptop - 16GB');
});

it('quotact updates a draft, replacing its lines, and refuses anything that left draft', function () {
    $tenant = Tenant::factory()->create();
    $actor = quotactActor($tenant);
    $save = app(SaveQuoteAction::class);

    $quote = $save->execute($actor, null, quotactData());
    $updated = $save->execute($actor, $quote->id, quotactData(['title' => 'Renamed', 'lines' => [quotactLine(['quantity' => '1', 'unit_price' => '10.00']), quotactLine(['name' => 'B', 'quantity' => '1', 'unit_price' => '5.00'])]]));

    expect($updated->quote_number)->toBe('Q-00001');
    expect($updated->title)->toBe('Renamed');
    expect($updated->lines)->toHaveCount(2);
    expect($updated->total)->toBe('15.00');
    expect(QuoteLine::query()->where('tenant_id', $tenant->id)->count())->toBe(2);

    DB::table('quotes')->where('id', $quote->id)->update(['status' => 'sent']);
    expect(fn () => $save->execute($actor, $quote->id, quotactData()))->toThrow(ValidationException::class);
    expect(fn () => app(DeleteQuoteAction::class)->execute($actor, $quote->id))->toThrow(ValidationException::class);
});

it('quotact rejects another tenants product, variant, contact or quote', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $actorA = quotactActor($a);
    $managerB = quotactActor($b, 'manager');
    $save = app(SaveQuoteAction::class);

    quotactSwitch($b);
    $theirProduct = app(SaveProductAction::class)->execute($managerB, null, [
        'sku' => 'B1', 'name' => 'Bravo', 'unit_price' => '1.00', 'tax_category' => 'zero', 'is_active' => true,
        'variants' => [['sku' => 'B1-V', 'name' => 'V']],
    ]);
    $theirContact = Contact::factory()->create();
    $theirQuote = $save->execute($managerB, null, quotactData());

    quotactSwitch($a);
    expect(fn () => $save->execute($actorA, null, quotactData(['lines' => [['product_id' => $theirProduct->id, 'quantity' => '1']]])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actorA, null, quotactData(['contact_id' => $theirContact->id])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actorA, $theirQuote->id, quotactData()))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(DeleteQuoteAction::class)->execute($actorA, $theirQuote->id))->toThrow(ModelNotFoundException::class);

    expect(Quote::query()->where('tenant_id', $a->id)->count())->toBe(0);
});

it('quotact deletes a draft together with its lines', function () {
    $tenant = Tenant::factory()->create();
    $actor = quotactActor($tenant);

    $quote = app(SaveQuoteAction::class)->execute($actor, null, quotactData());
    app(DeleteQuoteAction::class)->execute($actor, $quote->id);

    expect(Quote::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(QuoteLine::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('quotact gives the quote permissions to the right roles', function () {
    expect(RoleCatalog::permissionsFor('admin'))->toContain('quotes.view', 'quotes.manage');
    expect(RoleCatalog::permissionsFor('manager'))->toContain('quotes.view', 'quotes.manage');
    expect(RoleCatalog::permissionsFor('sales'))->toContain('quotes.view', 'quotes.manage');
    expect(RoleCatalog::permissionsFor('viewer'))->toContain('quotes.view')->not->toContain('quotes.manage');

    $tenant = Tenant::factory()->create();
    $viewer = quotactActor($tenant, 'viewer');
    expect(fn () => app(SaveQuoteAction::class)->execute($viewer, null, quotactData()))->toThrow(AuthorizationException::class);
    expect(fn () => app(DeleteQuoteAction::class)->execute($viewer, 1))->toThrow(AuthorizationException::class);
});

it('quotact re-validates fields server-side', function () {
    $tenant = Tenant::factory()->create();
    $actor = quotactActor($tenant);
    $save = app(SaveQuoteAction::class);

    expect(fn () => $save->execute($actor, null, quotactData(['title' => ' '])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, quotactData(['currency' => 'usd'])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, quotactData(['valid_until' => '2026-13-45'])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, quotactData(['lines' => [quotactLine(['quantity' => '0'])]])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, quotactData(['lines' => [quotactLine(['discount_percent' => '101'])]])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, quotactData(['lines' => [quotactLine(['name' => ''])]])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, quotactData(['lines' => [quotactLine(['unit_price' => ''])]])))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($actor, null, quotactData(['lines' => [quotactLine(['tax_category' => 'luxury'])]])))->toThrow(ValidationException::class);

    expect(Quote::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
