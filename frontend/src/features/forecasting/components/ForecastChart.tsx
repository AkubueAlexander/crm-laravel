import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { StageForecast } from '../schemas/forecast';

const STAGE_ORDER = ['lead', 'qualified', 'proposal', 'negotiation', 'won'];
const STAGE_LABELS: Record<string, string> = {
    lead: 'Lead',
    qualified: 'Qualified',
    proposal: 'Proposal',
    negotiation: 'Negotiation',
    won: 'Won',
};

function formatCurrency(value: number): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(value);
}

export function ForecastChart({ byStage }: { byStage: StageForecast[] }) {
    const chartData = STAGE_ORDER.map((stage) => {
        const entry = byStage.find((s) => s.stage === stage);
        return {
            stage: STAGE_LABELS[stage],
            total: entry?.total_amount ?? 0,
            weighted: entry?.weighted_amount ?? 0,
        };
    });

    return (
        <div className="h-72 rounded-lg border border-[var(--color-border)] bg-white p-4">
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chartData}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
                    <XAxis dataKey="stage" tick={{ fontSize: 12 }} />
                    <YAxis tick={{ fontSize: 12 }} />
                    <Tooltip formatter={(value: unknown) => formatCurrency(Number(value))} />
                    <Bar dataKey="total" name="Pipeline value" fill="var(--color-accent)" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="weighted" name="Weighted (best case)" fill="var(--color-accent)" fillOpacity={0.4} radius={[4, 4, 0, 0]} />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
