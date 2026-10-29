<?php

namespace App\Domain\Accounting;

use App\Models\LedgerAccount;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 12.0b: read-only aggregates over the append-only journal. Every query carries an explicit tenant predicate on both
 * the lines and the entries. All arithmetic is integer cents. These are OPERATIONAL reports (the books of record live
 * in QuickBooks/Xero): there are no closing entries, so the balance sheet shows "current earnings" as the running
 * revenue-minus-expense total up to the date.
 */
final class LedgerReports
{
    /** @return array<string, mixed> */
    public function trialBalance(int $tenantId, string $asOf): array
    {
        $rows = [];
        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($this->balances($tenantId, null, $asOf) as $r) {
            if ($r['debit'] === 0 && $r['credit'] === 0) {
                continue;
            }

            $net = $r['debit'] - $r['credit'];
            $debit = max($net, 0);
            $credit = max(-$net, 0);
            $debitTotal += $debit;
            $creditTotal += $credit;

            $rows[] = $this->identify($r['account']) + ['debit' => $this->money($debit), 'credit' => $this->money($credit)];
        }

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => ['debit' => $this->money($debitTotal), 'credit' => $this->money($creditTotal), 'balanced' => $debitTotal === $creditTotal],
        ];
    }

    /** @return array<string, mixed> */
    public function incomeStatement(int $tenantId, string $from, string $to): array
    {
        $revenue = [];
        $expenses = [];
        $revenueTotal = 0;
        $expenseTotal = 0;

        foreach ($this->balances($tenantId, $from, $to) as $r) {
            $type = $r['account']->type;

            if ($type === 'revenue') {
                $amount = $r['credit'] - $r['debit'];
                if ($amount !== 0) {
                    $revenue[] = $this->identify($r['account']) + ['amount' => $this->money($amount)];
                    $revenueTotal += $amount;
                }
            } elseif ($type === 'expense') {
                $amount = $r['debit'] - $r['credit'];
                if ($amount !== 0) {
                    $expenses[] = $this->identify($r['account']) + ['amount' => $this->money($amount)];
                    $expenseTotal += $amount;
                }
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => $this->money($revenueTotal),
            'total_expenses' => $this->money($expenseTotal),
            'net_income' => $this->money($revenueTotal - $expenseTotal),
        ];
    }

    /** @return array<string, mixed> */
    public function balanceSheet(int $tenantId, string $asOf): array
    {
        $assets = [];
        $liabilities = [];
        $equity = [];
        $assetTotal = 0;
        $liabilityTotal = 0;
        $equityTotal = 0;
        $revenueTotal = 0;
        $expenseTotal = 0;

        foreach ($this->balances($tenantId, null, $asOf) as $r) {
            $type = $r['account']->type;
            $debitNormal = $r['debit'] - $r['credit'];

            if ($type === 'asset' && $debitNormal !== 0) {
                $assets[] = $this->identify($r['account']) + ['amount' => $this->money($debitNormal)];
                $assetTotal += $debitNormal;
            } elseif ($type === 'liability' && $debitNormal !== 0) {
                $liabilities[] = $this->identify($r['account']) + ['amount' => $this->money(-$debitNormal)];
                $liabilityTotal += -$debitNormal;
            } elseif ($type === 'equity' && $debitNormal !== 0) {
                $equity[] = $this->identify($r['account']) + ['amount' => $this->money(-$debitNormal)];
                $equityTotal += -$debitNormal;
            } elseif ($type === 'revenue') {
                $revenueTotal += -$debitNormal;
            } elseif ($type === 'expense') {
                $expenseTotal += $debitNormal;
            }
        }

        $earnings = $revenueTotal - $expenseTotal;
        $equityWithEarnings = $equityTotal + $earnings;

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_earnings' => $this->money($earnings),
            'total_assets' => $this->money($assetTotal),
            'total_liabilities' => $this->money($liabilityTotal),
            'total_equity' => $this->money($equityWithEarnings),
            'total_liabilities_and_equity' => $this->money($liabilityTotal + $equityWithEarnings),
            'balanced' => $assetTotal === $liabilityTotal + $equityWithEarnings,
        ];
    }

    /**
     * One account's lines with a running balance on the account's normal side (debit-normal: debits minus credits;
     * credit-normal: the reverse). The running total is computed over ALL the account's lines in date order, then
     * the date range and the page are cut from it, so a page always shows the true balance.
     *
     * @return array{account: LedgerAccount, opening: string, paginator: LengthAwarePaginator}
     */
    public function accountLedger(int $tenantId, int $accountId, ?string $from, ?string $to, int $perPage, int $page): array
    {
        // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
        $account = LedgerAccount::query()->where('tenant_id', $tenantId)->findOrFail($accountId);
        $sign = in_array($account->type, LedgerAccount::DEBIT_NORMAL, true) ? 1 : -1;

        $opening = 0;
        if ($from !== null) {
            $row = $this->lines($tenantId)
                ->where('l.ledger_account_id', $account->id)
                ->where('e.entry_date', '<', $from)
                ->selectRaw('coalesce(sum(l.debit), 0) as d, coalesce(sum(l.credit), 0) as c')
                ->first();
            $opening = $sign * ($this->cents($row->d) - $this->cents($row->c));
        }

        $inner = $this->lines($tenantId)
            ->where('l.ledger_account_id', $account->id)
            ->selectRaw('e.id as entry_id, e.entry_number, e.entry_date, e.memo, l.description, l.debit, l.credit, l.id as line_id, sum(l.debit - l.credit) over (order by e.entry_date, e.id, l.id) as running');

        $paginator = DB::query()
            ->fromSub($inner, 't')
            ->when($from !== null, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('entry_date', '<=', $to))
            ->orderBy('entry_date')
            ->orderBy('entry_id')
            ->orderBy('line_id')
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection($paginator->getCollection()->map(fn ($r) => [
            'entry_id' => (int) $r->entry_id,
            'entry_number' => $r->entry_number,
            'entry_date' => substr((string) $r->entry_date, 0, 10),
            'memo' => $r->memo,
            'description' => $r->description,
            'debit' => $this->money($this->cents($r->debit)),
            'credit' => $this->money($this->cents($r->credit)),
            'balance' => $this->money($sign * $this->cents($r->running)),
        ])->values());

        return ['account' => $account, 'opening' => $this->money($opening), 'paginator' => $paginator];
    }

    /**
     * Every account of the tenant (chart order) with its debit and credit totals over the date range (inclusive).
     *
     * @return list<array{account: LedgerAccount, debit: int, credit: int}>
     */
    private function balances(int $tenantId, ?string $from, ?string $to): array
    {
        $sums = $this->lines($tenantId)
            ->when($from !== null, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('e.entry_date', '<=', $to))
            ->groupBy('l.ledger_account_id')
            ->selectRaw('l.ledger_account_id as account_id, sum(l.debit) as d, sum(l.credit) as c')
            ->get()
            ->keyBy('account_id');

        return LedgerAccount::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->get()
            ->map(fn (LedgerAccount $a) => [
                'account' => $a,
                'debit' => $this->cents($sums->get($a->id)->d ?? 0),
                'credit' => $this->cents($sums->get($a->id)->c ?? 0),
            ])
            ->all();
    }

    /** Lines joined to their entries, both pinned to the tenant. */
    private function lines(int $tenantId)
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.tenant_id', $tenantId)
            ->where('e.tenant_id', $tenantId);
    }

    /** @return array{account_id: int, code: string, name: string, type: string} */
    private function identify(LedgerAccount $a): array
    {
        return ['account_id' => (int) $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type];
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
