<?php

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Domain\Accounting\Actions\SaveLedgerAccountAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function ledgapiUser(Tenant $tenant, ?string $role = 'manager'): User
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

function ledgapiAccount(User $actor, string $code, string $name, string $type): int
{
    return app(SaveLedgerAccountAction::class)->execute($actor, null, ['code' => $code, 'name' => $name, 'type' => $type])->id;
}

function ledgapiPost(User $actor, string $date, string $memo, array $lines)
{
    return app(PostJournalEntryAction::class)->execute($actor, $date, $memo, $lines);
}

it('ledgapi manages the chart of accounts over the API', function () {
    $b = Tenant::factory()->create();
    $theirs = ledgapiAccount(ledgapiUser($b), '9999', 'Theirs', 'asset');

    $a = Tenant::factory()->create();
    $mgr = ledgapiUser($a);
    $viewer = ledgapiUser($a, 'viewer');
    $sales = ledgapiUser($a, 'sales');

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/accounting/accounts')->assertForbidden();

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/accounting/accounts')->assertOk()->assertJsonPath('meta.total', 0);
    $this->postJson('/api/v1/accounting/accounts/defaults')->assertForbidden();
    $this->postJson('/api/v1/accounting/accounts', ['code' => '1', 'name' => 'X', 'type' => 'asset'])->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson('/api/v1/accounting/accounts/defaults')->assertOk()->assertJsonPath('created', 9);
    $this->postJson('/api/v1/accounting/accounts/defaults')->assertOk()->assertJsonPath('created', 0);

    $this->getJson('/api/v1/accounting/accounts')
        ->assertOk()
        ->assertJsonPath('meta.total', 9)
        ->assertJsonPath('data.0.code', '1000')
        ->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson('/api/v1/accounting/accounts?type=liability')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/accounting/accounts?q=cash')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/accounts?active=0')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/accounting/accounts?type=bogus')->assertStatus(422)->assertJsonValidationErrors('type');

    $this->postJson('/api/v1/accounting/accounts', [])->assertStatus(422)->assertJsonValidationErrors(['code', 'name', 'type']);
    $this->postJson('/api/v1/accounting/accounts', ['code' => '1000', 'name' => 'Dup', 'type' => 'asset'])->assertStatus(422)->assertJsonValidationErrors('code');
    $id = (int) $this->postJson('/api/v1/accounting/accounts', ['code' => '4100', 'name' => 'Services', 'type' => 'revenue'])
        ->assertCreated()
        ->assertJsonPath('is_active', true)
        ->json('id');
    $this->putJson("/api/v1/accounting/accounts/{$id}", ['code' => '4100', 'name' => 'Service revenue', 'type' => 'revenue', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('name', 'Service revenue')
        ->assertJsonPath('is_active', false);
    $this->putJson("/api/v1/accounting/accounts/{$theirs}", ['code' => '9999', 'name' => 'Hijack', 'type' => 'asset'])->assertNotFound();
});

it('ledgapi posts, lists, shows and reverses journal entries', function () {
    $b = Tenant::factory()->create();
    $bMgr = ledgapiUser($b);
    $bCash = ledgapiAccount($bMgr, '1000', 'Cash', 'asset');
    $bRev = ledgapiAccount($bMgr, '4000', 'Sales', 'revenue');
    $theirs = ledgapiPost($bMgr, '2026-10-10', 'Theirs', [
        ['account_id' => $bCash, 'debit' => '5', 'credit' => ''],
        ['account_id' => $bRev, 'debit' => '', 'credit' => '5'],
    ])->id;

    $a = Tenant::factory()->create();
    $mgr = ledgapiUser($a);
    $viewer = ledgapiUser($a, 'viewer');
    $cash = ledgapiAccount($mgr, '1000', 'Cash', 'asset');
    $rev = ledgapiAccount($mgr, '4000', 'Sales', 'revenue');

    $payload = [
        'entry_date' => '2026-10-10',
        'memo' => 'Sale',
        'lines' => [
            ['account_id' => $cash, 'debit' => 100, 'credit' => ''],
            ['account_id' => $rev, 'debit' => '', 'credit' => '100.00'],
        ],
    ];

    Sanctum::actingAs($viewer);
    $this->postJson('/api/v1/accounting/journal-entries', $payload)->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson('/api/v1/accounting/journal-entries', [])->assertStatus(422)->assertJsonValidationErrors(['entry_date', 'lines']);
    $unbalanced = $payload;
    $unbalanced['lines'][1]['credit'] = '99.99';
    $this->postJson('/api/v1/accounting/journal-entries', $unbalanced)->assertStatus(422)->assertJsonValidationErrors('lines');

    $id = (int) $this->postJson('/api/v1/accounting/journal-entries', $payload)
        ->assertCreated()
        ->assertJsonPath('entry_number', 'JE-0001')
        ->assertJsonPath('total', '100.00')
        ->assertJsonPath('lines.0.account_code', '1000')
        ->assertJsonPath('lines.0.debit', '100.00')
        ->assertJsonPath('lines.1.credit', '100.00')
        ->assertJsonMissingPath('tenant_id')
        ->json('id');

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/accounting/journal-entries')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.total', '100.00')
        ->assertJsonPath('data.0.reversed_by_entry_id', null)
        ->assertJsonMissingPath('data.0.tenant_id');
    $this->getJson("/api/v1/accounting/journal-entries?account_id={$cash}")->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?account_id=999999')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/accounting/journal-entries?from=2026-10-11')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/accounting/journal-entries?to=2026-10-10')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?q=JE-0001')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?q=sale')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/accounting/journal-entries?from=10/10/2026')->assertStatus(422)->assertJsonValidationErrors('from');
    $this->getJson("/api/v1/accounting/journal-entries/{$id}")->assertOk()->assertJsonPath('lines.1.account_name', 'Sales');
    $this->getJson("/api/v1/accounting/journal-entries/{$theirs}")->assertNotFound();
    $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", ['reason' => 'Oops'])->assertForbidden();

    Sanctum::actingAs($mgr);
    $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $reversalId = (int) $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", ['reason' => 'Posted twice', 'entry_date' => '2026-10-11'])
        ->assertCreated()
        ->assertJsonPath('entry_number', 'JE-0002')
        ->assertJsonPath('reverses_entry_id', $id)
        ->assertJsonPath('lines.0.credit', '100.00')
        ->json('id');
    $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", ['reason' => 'Again'])->assertStatus(422)->assertJsonValidationErrors('entry');
    $this->postJson("/api/v1/accounting/journal-entries/{$reversalId}/reverse", ['reason' => 'Undo'])->assertStatus(422)->assertJsonValidationErrors('entry');
    $this->postJson("/api/v1/accounting/journal-entries/{$theirs}/reverse", ['reason' => 'Hijack'])->assertNotFound();

    $this->getJson('/api/v1/accounting/journal-entries')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $reversalId);
    $this->getJson("/api/v1/accounting/journal-entries/{$id}")->assertOk()->assertJsonPath('reversed_by_entry_id', $reversalId);
});

it('ledgapi serves the trial balance, income statement, balance sheet and account ledger', function () {
    $b = Tenant::factory()->create();
    $bMgr = ledgapiUser($b);
    $bCash = ledgapiAccount($bMgr, '1000', 'Cash', 'asset');
    $bEquity = ledgapiAccount($bMgr, '3000', 'Equity', 'equity');
    ledgapiPost($bMgr, '2026-10-02', 'Theirs', [
        ['account_id' => $bCash, 'debit' => '9999', 'credit' => ''],
        ['account_id' => $bEquity, 'debit' => '', 'credit' => '9999'],
    ]);

    $a = Tenant::factory()->create();
    $mgr = ledgapiUser($a);
    $viewer = ledgapiUser($a, 'viewer');
    $sales = ledgapiUser($a, 'sales');
    $cash = ledgapiAccount($mgr, '1000', 'Cash', 'asset');
    $ar = ledgapiAccount($mgr, '1100', 'Accounts receivable', 'asset');
    $tax = ledgapiAccount($mgr, '2200', 'Sales tax payable', 'liability');
    $equity = ledgapiAccount($mgr, '3000', 'Equity', 'equity');
    $rev = ledgapiAccount($mgr, '4000', 'Sales', 'revenue');
    $cogs = ledgapiAccount($mgr, '5000', 'Cost of goods sold', 'expense');

    ledgapiPost($mgr, '2026-10-01', 'Investment', [
        ['account_id' => $cash, 'debit' => '1000', 'credit' => ''],
        ['account_id' => $equity, 'debit' => '', 'credit' => '1000'],
    ]);
    ledgapiPost($mgr, '2026-10-10', 'Sale', [
        ['account_id' => $ar, 'debit' => '500', 'credit' => ''],
        ['account_id' => $rev, 'debit' => '', 'credit' => '450'],
        ['account_id' => $tax, 'debit' => '', 'credit' => '50'],
    ]);
    ledgapiPost($mgr, '2026-10-15', 'Cost', [
        ['account_id' => $cogs, 'debit' => '200', 'credit' => ''],
        ['account_id' => $cash, 'debit' => '', 'credit' => '200'],
    ]);

    Sanctum::actingAs($sales);
    $this->getJson('/api/v1/accounting/reports/trial-balance')->assertForbidden();

    Sanctum::actingAs($viewer);

    // Trial balance
    $this->getJson('/api/v1/accounting/reports/trial-balance?as_of=2026-10-31')
        ->assertOk()
        ->assertJsonCount(6, 'rows')
        ->assertJsonPath('rows.0.code', '1000')
        ->assertJsonPath('rows.0.debit', '800.00')
        ->assertJsonPath('rows.0.credit', '0.00')
        ->assertJsonPath('rows.2.credit', '50.00')
        ->assertJsonPath('rows.5.debit', '200.00')
        ->assertJsonPath('totals.debit', '1500.00')
        ->assertJsonPath('totals.credit', '1500.00')
        ->assertJsonPath('totals.balanced', true);
    $this->getJson('/api/v1/accounting/reports/trial-balance?as_of=2026-10-05')
        ->assertOk()
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('totals.debit', '1000.00')
        ->assertJsonPath('totals.balanced', true);
    $this->getJson('/api/v1/accounting/reports/trial-balance?as_of=nope')->assertStatus(422)->assertJsonValidationErrors('as_of');

    // Income statement
    $this->getJson('/api/v1/accounting/reports/income-statement?from=2026-10-01&to=2026-10-31')
        ->assertOk()
        ->assertJsonPath('revenue.0.code', '4000')
        ->assertJsonPath('revenue.0.amount', '450.00')
        ->assertJsonPath('expenses.0.amount', '200.00')
        ->assertJsonPath('total_revenue', '450.00')
        ->assertJsonPath('total_expenses', '200.00')
        ->assertJsonPath('net_income', '250.00');
    $this->getJson('/api/v1/accounting/reports/income-statement?from=2026-10-12&to=2026-10-31')
        ->assertOk()
        ->assertJsonPath('total_revenue', '0.00')
        ->assertJsonPath('net_income', '-200.00');
    $this->getJson('/api/v1/accounting/reports/income-statement?from=2026-10-31&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');

    // Balance sheet
    $this->getJson('/api/v1/accounting/reports/balance-sheet?as_of=2026-10-31')
        ->assertOk()
        ->assertJsonPath('assets.0.amount', '800.00')
        ->assertJsonPath('assets.1.amount', '500.00')
        ->assertJsonPath('total_assets', '1300.00')
        ->assertJsonPath('total_liabilities', '50.00')
        ->assertJsonPath('equity.0.amount', '1000.00')
        ->assertJsonPath('current_earnings', '250.00')
        ->assertJsonPath('total_equity', '1250.00')
        ->assertJsonPath('total_liabilities_and_equity', '1300.00')
        ->assertJsonPath('balanced', true);

    // Account ledger with running balance
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$cash}&from=2026-10-01&to=2026-10-31")
        ->assertOk()
        ->assertJsonPath('account.code', '1000')
        ->assertJsonPath('opening_balance', '0.00')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.debit', '1000.00')
        ->assertJsonPath('data.0.balance', '1000.00')
        ->assertJsonPath('data.1.credit', '200.00')
        ->assertJsonPath('data.1.balance', '800.00')
        ->assertJsonPath('meta.total', 2);
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$cash}&from=2026-10-10")
        ->assertOk()
        ->assertJsonPath('opening_balance', '1000.00')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.balance', '800.00');
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$equity}")
        ->assertOk()
        ->assertJsonPath('data.0.credit', '1000.00')
        ->assertJsonPath('data.0.balance', '1000.00'); // credit-normal: shown on its own side
    $this->getJson('/api/v1/accounting/reports/ledger')->assertStatus(422)->assertJsonValidationErrors('account_id');
    $this->getJson("/api/v1/accounting/reports/ledger?account_id={$bCash}")->assertNotFound();
});
