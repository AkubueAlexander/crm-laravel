<?php

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Domain\Accounting\Actions\ReverseJournalEntryAction;
use App\Domain\Accounting\Actions\SaveLedgerAccountAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function ledgUser(Tenant $tenant, ?string $role = 'manager'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    if ($role !== null) {
        $user->assignRole($role);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function ledgAccount(User $actor, string $code, string $name, string $type): int
{
    return app(SaveLedgerAccountAction::class)->execute($actor, null, ['code' => $code, 'name' => $name, 'type' => $type])->id;
}

function ledgLine(int $account, string $debit, string $credit): array
{
    return ['account_id' => $account, 'debit' => $debit, 'credit' => $credit];
}

function ledgPost(User $actor, array $lines, string $date = '2026-10-29', ?string $memo = 'Test')
{
    return app(PostJournalEntryAction::class)->execute($actor, $date, $memo, $lines);
}

/** @return array{0: string, 1: string} total debit and credit posted to an account */
function ledgTotals(int $tenantId, int $accountId): array
{
    $row = DB::table('journal_lines')->where('tenant_id', $tenantId)->where('ledger_account_id', $accountId)
        ->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->first();

    return [(string) $row->d, (string) $row->c];
}

it('ledg manages the chart of accounts with unique codes, frozen history and tenant isolation', function () {
    $b = Tenant::factory()->create();
    $theirs = ledgAccount(ledgUser($b), '1000', 'Theirs', 'asset');

    $a = Tenant::factory()->create();
    $mgr = ledgUser($a);
    $viewer = ledgUser($a, 'viewer');
    $sales = ledgUser($a, 'sales');
    $save = app(SaveLedgerAccountAction::class);

    $cash = $save->execute($mgr, null, ['code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
    expect($cash->code)->toBe('1000')->and($cash->is_active)->toBeTrue();
    expect($save->execute($mgr, null, ['code' => 'ar-1', 'name' => 'Receivables', 'type' => 'asset'])->code)->toBe('AR-1');

    expect(fn () => $save->execute($mgr, null, ['code' => '1000', 'name' => 'Dup', 'type' => 'asset']))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, null, ['code' => '!!', 'name' => 'Bad', 'type' => 'asset']))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, null, ['code' => '2000', 'name' => '', 'type' => 'asset']))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, null, ['code' => '2000', 'name' => 'X', 'type' => 'bogus']))->toThrow(ValidationException::class);

    // Before any posting: code and type can still change.
    $rev = $save->execute($mgr, null, ['code' => '4000', 'name' => 'Sales', 'type' => 'asset']);
    $rev = $save->execute($mgr, $rev->id, ['code' => '4100', 'name' => 'Sales', 'type' => 'revenue']);
    expect($rev->code)->toBe('4100')->and($rev->type)->toBe('revenue');

    ledgPost($mgr, [ledgLine($cash->id, '10.00', ''), ledgLine($rev->id, '', '10.00')]);

    // After posting: frozen, except name and active flag.
    expect(fn () => $save->execute($mgr, $rev->id, ['code' => '4200', 'name' => 'Sales', 'type' => 'revenue']))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($mgr, $rev->id, ['code' => '4100', 'name' => 'Sales', 'type' => 'expense']))->toThrow(ValidationException::class);
    $renamed = $save->execute($mgr, $rev->id, ['code' => '4100', 'name' => 'Product sales', 'type' => 'revenue', 'is_active' => false]);
    expect($renamed->name)->toBe('Product sales')->and($renamed->is_active)->toBeFalse();

    expect(fn () => $save->execute($viewer, null, ['code' => '9', 'name' => 'Z', 'type' => 'asset']))->toThrow(AuthorizationException::class);
    expect(fn () => $save->execute($sales, null, ['code' => '9', 'name' => 'Z', 'type' => 'asset']))->toThrow(AuthorizationException::class);
    expect(fn () => $save->execute($mgr, $theirs, ['code' => '1000', 'name' => 'Hijack', 'type' => 'asset']))->toThrow(ModelNotFoundException::class);
});

it('ledg posts only balanced, well-formed, append-only entries', function () {
    $b = Tenant::factory()->create();
    $theirAccount = ledgAccount(ledgUser($b), '1000', 'Theirs', 'asset');

    $a = Tenant::factory()->create();
    $mgr = ledgUser($a);
    $viewer = ledgUser($a, 'viewer');
    $cash = ledgAccount($mgr, '1000', 'Cash', 'asset');
    $rev = ledgAccount($mgr, '4000', 'Sales', 'revenue');
    $tax = ledgAccount($mgr, '2200', 'Sales tax', 'liability');
    $dead = ledgAccount($mgr, '9000', 'Dead', 'expense');
    app(SaveLedgerAccountAction::class)->execute($mgr, $dead, ['code' => '9000', 'name' => 'Dead', 'type' => 'expense', 'is_active' => false]);

    $entry = ledgPost($mgr, [ledgLine($cash, '100.00', ''), ledgLine($rev, '', '90'), ledgLine($tax, '', '10.00')]);
    expect($entry->entry_number)->toBe('JE-0001')->and($entry->lines)->toHaveCount(3)->and($entry->lines[1]->credit)->toBe('90.00')->and($entry->lines[1]->debit)->toBe('0.00');
    expect(ledgPost($mgr, [ledgLine($cash, '1', ''), ledgLine($rev, '', '1')])->entry_number)->toBe('JE-0002');

    $bad = fn (array $lines, string $date = '2026-10-29') => fn () => ledgPost($mgr, $lines, $date);
    expect($bad([ledgLine($cash, '100', ''), ledgLine($rev, '', '99.99')]))->toThrow(ValidationException::class); // unbalanced
    expect($bad([ledgLine($cash, '100', '')]))->toThrow(ValidationException::class); // one line
    expect($bad([ledgLine($cash, '100', '100'), ledgLine($rev, '', '0')]))->toThrow(ValidationException::class); // both sides
    expect($bad([ledgLine($cash, '0', ''), ledgLine($rev, '', '')]))->toThrow(ValidationException::class); // zero line
    expect($bad([ledgLine($cash, 'abc', ''), ledgLine($rev, '', '1')]))->toThrow(ValidationException::class);
    expect($bad([ledgLine($cash, '1.234', ''), ledgLine($rev, '', '1.23')]))->toThrow(ValidationException::class);
    expect($bad([ledgLine($cash, '1', ''), ledgLine($dead, '', '1')]))->toThrow(ValidationException::class); // inactive account
    expect($bad([ledgLine($cash, '1', ''), ledgLine($theirAccount, '', '1')]))->toThrow(ValidationException::class); // other tenant
    expect($bad([ledgLine($cash, '1', ''), ledgLine(999999, '', '1')]))->toThrow(ValidationException::class);
    expect($bad([ledgLine($cash, '1', ''), ledgLine($rev, '', '1')], '29/10/2026'))->toThrow(ValidationException::class);
    expect(fn () => ledgPost($viewer, [ledgLine($cash, '1', ''), ledgLine($rev, '', '1')]))->toThrow(AuthorizationException::class);

    // Nothing from the refused attempts was written.
    expect(DB::table('journal_entries')->where('tenant_id', $a->id)->count())->toBe(2);

    // Append-only.
    expect(fn () => $entry->forceFill(['memo' => 'edited'])->save())->toThrow(LogicException::class);
    expect(fn () => $entry->lines[0]->delete())->toThrow(LogicException::class);
});

it('ledg reverses an entry exactly once with swapped lines, even onto an inactive account', function () {
    $a = Tenant::factory()->create();
    $mgr = ledgUser($a);
    $viewer = ledgUser($a, 'viewer');
    $cash = ledgAccount($mgr, '1000', 'Cash', 'asset');
    $rev = ledgAccount($mgr, '4000', 'Sales', 'revenue');
    $reverse = app(ReverseJournalEntryAction::class);

    $entry = ledgPost($mgr, [ledgLine($cash, '50.00', ''), ledgLine($rev, '', '50.00')]);
    expect(ledgTotals($a->id, $cash))->toBe(['50.00', '0.00']);

    expect(fn () => $reverse->execute($mgr, $entry->id, ''))->toThrow(ValidationException::class);
    expect(fn () => $reverse->execute($viewer, $entry->id, 'Oops'))->toThrow(AuthorizationException::class);
    expect(fn () => $reverse->execute($mgr, 999999, 'Oops'))->toThrow(ModelNotFoundException::class);

    // The revenue account is deactivated afterwards: the reversal must still work.
    app(SaveLedgerAccountAction::class)->execute($mgr, $rev, ['code' => '4000', 'name' => 'Sales', 'type' => 'revenue', 'is_active' => false]);

    $reversal = $reverse->execute($mgr, $entry->id, 'Posted twice by mistake');
    expect($reversal->entry_number)->toBe('JE-0002')
        ->and($reversal->reverses_entry_id)->toBe($entry->id)
        ->and($reversal->memo)->toContain('JE-0001')
        ->and($reversal->lines)->toHaveCount(2)
        ->and($reversal->lines[0]->credit)->toBe('50.00')
        ->and($reversal->lines[1]->debit)->toBe('50.00');
    expect(ledgTotals($a->id, $cash))->toBe(['50.00', '50.00'])->and(ledgTotals($a->id, $rev))->toBe(['50.00', '50.00']);

    expect(fn () => $reverse->execute($mgr, $entry->id, 'Again'))->toThrow(ValidationException::class); // already reversed
    expect(fn () => $reverse->execute($mgr, $reversal->id, 'Undo the undo'))->toThrow(ValidationException::class); // reversal of a reversal
    expect(DB::table('journal_entries')->where('tenant_id', $a->id)->count())->toBe(2);
});
