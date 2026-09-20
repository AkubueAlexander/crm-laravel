import type { ForecastResult } from '../schemas/forecast';

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(amount);
}

export function ForecastSummary({ forecast }: { forecast: ForecastResult }) {
    return (
        <div className="flex gap-4">
            <div className="flex-1 rounded-lg border border-[var(--color-border)] bg-white p-4">
                <div className="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">Commit</div>
                <div className="mt-1 text-2xl font-semibold text-[var(--color-ink)]">
                    {formatCurrency(forecast.total_commit)}
                </div>
            </div>
            <div className="flex-1 rounded-lg border border-[var(--color-border)] bg-white p-4">
                <div className="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">Best Case</div>
                <div className="mt-1 text-2xl font-semibold text-[var(--color-ink)]">
                    {formatCurrency(forecast.total_best_case)}
                </div>
            </div>
        </div>
    );
}
