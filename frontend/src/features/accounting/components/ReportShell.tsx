import type { ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { formatLedgerAmount } from '../schemas/accounting';
import type { StatementRow } from '../schemas/reports';

const TAB_CLASS = 'rounded-md px-2 py-1 text-sm text-[var(--color-ink-muted)] hover:bg-[var(--color-paper)] [&.active]:bg-[var(--color-paper)] [&.active]:font-medium [&.active]:text-[var(--color-ink)]';

// 12.1b: shared chrome for the report pages: tabs plus the mandatory "not your books of record" banner (12.1).
export function ReportShell({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/accounting" className="text-[var(--color-ink-muted)] underline">
                    Journal
                </Link>
            </div>
            <h1 className="mb-3 text-lg font-medium text-[var(--color-ink)]">{title}</h1>
            <nav className="mb-3 flex flex-wrap gap-1" aria-label="Reports">
                <Link to="/accounting/reports/trial-balance" className={TAB_CLASS}>
                    Trial balance
                </Link>
                <Link to="/accounting/reports/income-statement" className={TAB_CLASS}>
                    Income statement
                </Link>
                <Link to="/accounting/reports/balance-sheet" className={TAB_CLASS}>
                    Balance sheet
                </Link>
                <Link to="/accounting/reports/ledger" className={TAB_CLASS}>
                    Account ledger
                </Link>
            </nav>
            <p role="note" className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Operational reporting, not your books of record (QuickBooks/Xero are).
            </p>
            {children}
        </div>
    );
}

export function DateFilter({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
    return (
        <label className="flex items-center gap-1 text-sm">
            {label}
            <input type="date" value={value} onChange={(e) => onChange(e.target.value)} className="rounded-md border px-2 py-2 text-sm" />
        </label>
    );
}

export function Badge({ ok, okText, badText }: { ok: boolean; okText: string; badText: string }) {
    return (
        <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>{ok ? okText : badText}</span>
    );
}

export function StatementSection({
    title,
    rows,
    totalLabel,
    total,
    extra,
}: {
    title: string;
    rows: StatementRow[];
    totalLabel: string;
    total: string;
    extra?: { label: string; amount: string };
}) {
    return (
        <section className="mb-6">
            <h2 className="mb-2 text-base font-medium text-[var(--color-ink)]">{title}</h2>
            <table className="w-full border-collapse text-sm">
                <tbody>
                    {rows.map((r) => (
                        <tr key={`${r.code}-${r.name}`}>
                            <td className="w-24 border-b px-3 py-2 font-mono text-xs">
                                {r.account_id ? (
                                    <Link to="/accounting/reports/ledger" search={{ account_id: r.account_id }} className="underline">
                                        {r.code}
                                    </Link>
                                ) : (
                                    r.code
                                )}
                            </td>
                            <td className="border-b px-3 py-2">{r.name}</td>
                            <td className="border-b px-3 py-2 text-right">{formatLedgerAmount(r.amount)}</td>
                        </tr>
                    ))}
                    {extra && (
                        <tr>
                            <td className="border-b px-3 py-2" />
                            <td className="border-b px-3 py-2 italic">{extra.label}</td>
                            <td className="border-b px-3 py-2 text-right">{formatLedgerAmount(extra.amount)}</td>
                        </tr>
                    )}
                    {rows.length === 0 && !extra && (
                        <tr>
                            <td colSpan={3} className="px-3 py-4 text-center text-gray-500">
                                No accounts with a balance.
                            </td>
                        </tr>
                    )}
                </tbody>
                <tfoot>
                    <tr>
                        <td colSpan={2} className="px-3 py-2 font-medium">
                            {totalLabel}
                        </td>
                        <td className="px-3 py-2 text-right font-medium">{formatLedgerAmount(total)}</td>
                    </tr>
                </tfoot>
            </table>
        </section>
    );
}
