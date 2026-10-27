import { Link, useParams } from '@tanstack/react-router';
import { Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useCampaignReport } from '../api/useCampaignReport';
import { MarketingTabs } from './MarketingTabs';

const DASH = '\u2014';

function pct(rate: number | null): string {
    return rate === null ? DASH : `${rate}%`;
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
            <p className="text-xs text-[var(--color-ink-muted)]">{label}</p>
            <p className="mt-1 text-xl font-medium text-[var(--color-ink)]">{value}</p>
            {sub && <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">{sub}</p>}
        </div>
    );
}

export function CampaignDashboard() {
    const allowed = usePermission('marketing.view');
    const params = useParams({ strict: false }) as { campaignId?: string };
    const campaignId = Number(params.campaignId);
    const valid = Number.isInteger(campaignId) && campaignId > 0;
    const { data, isPending, isFetching, isError, error } = useCampaignReport(valid ? campaignId : 0);

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to marketing.</p>;
    }

    const n = (v: number) => v.toLocaleString();

    return (
        <div>
            <MarketingTabs />

            <div className="mb-4 flex items-center justify-between">
                <div>
                    <Link to="/marketing" className="text-xs text-[var(--color-ink-muted)] hover:underline">
                        &larr; Campaigns
                    </Link>
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">
                        {data ? data.campaign.name : 'Campaign report'}
                        {data && (
                            <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium capitalize text-gray-700">
                                {data.campaign.status}
                            </span>
                        )}
                    </h1>
                </div>
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
            </div>

            {!valid && <p className="text-sm text-red-700">Invalid campaign.</p>}

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load the campaign report.")}
                </p>
            )}

            {valid && isPending && <p className="text-sm text-gray-500">Loading...</p>}

            {data && (
                <>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Stat label="Recipients" value={n(data.totals.recipients)} />
                        <Stat
                            label="Sent"
                            value={n(data.totals.sent)}
                            sub={data.totals.failed > 0 ? `${n(data.totals.failed)} failed` : undefined}
                        />
                        <Stat label="Delivered" value={n(data.totals.delivered)} />
                        <Stat label="Unsubscribed" value={n(data.totals.unsubscribed)} sub={pct(data.rates.unsubscribe)} />
                        <Stat label="Opened" value={n(data.totals.opened)} sub={`${pct(data.rates.open)} of delivered`} />
                        <Stat label="Clicked" value={n(data.totals.clicked)} sub={`${pct(data.rates.click)} of delivered`} />
                        <Stat label="Bounced" value={n(data.totals.bounced)} sub={`${pct(data.rates.bounce)} of sent`} />
                        <Stat label="Complaints" value={n(data.totals.complained)} />
                    </div>

                    <section className="mt-6 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
                        <h2 className="mb-3 text-sm font-medium text-[var(--color-ink)]">Activity by day</h2>
                        {data.series.length === 0 ? (
                            <p className="text-sm text-[var(--color-ink-muted)]">No activity yet.</p>
                        ) : (
                            <div style={{ width: '100%', height: 280 }}>
                                <ResponsiveContainer>
                                    <ComposedChart data={data.series}>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="date" tickFormatter={(d: string) => d.slice(5)} fontSize={12} />
                                        <YAxis allowDecimals={false} fontSize={12} />
                                        <Tooltip />
                                        <Legend />
                                        <Bar dataKey="sent" name="Sent" fill="#cbd5e1" />
                                        <Line type="monotone" dataKey="opened" name="Opened" stroke="#2563eb" strokeWidth={2} dot={false} />
                                        <Line type="monotone" dataKey="clicked" name="Clicked" stroke="#16a34a" strokeWidth={2} dot={false} />
                                        <Line type="monotone" dataKey="bounced" name="Bounced" stroke="#dc2626" strokeWidth={2} dot={false} />
                                    </ComposedChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </section>

                    <section className="mt-6">
                        <h2 className="mb-2 text-sm font-medium text-[var(--color-ink)]">Attribution</h2>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <Stat label="Leads" value={n(data.attribution.leads)} />
                            <Stat label="Deals" value={n(data.attribution.deals)} />
                        </div>
                        <p className="mt-2 text-xs text-[var(--color-ink-muted)]">
                            Matched on utm_campaign equal to this campaign&apos;s name (or its slug), case-insensitive.
                        </p>
                    </section>
                </>
            )}
        </div>
    );
}
