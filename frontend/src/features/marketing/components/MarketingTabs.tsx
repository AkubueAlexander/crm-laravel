import { Link } from '@tanstack/react-router';

const BASE = 'rounded-md px-3 py-1.5 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]';
const ACTIVE = { className: 'bg-[var(--color-surface)] font-medium text-[var(--color-ink)] shadow-sm' };

// Sub-navigation of the Marketing area. Each page renders it at the top; the AppShell nav item stays "Marketing".
export function MarketingTabs() {
    return (
        <nav aria-label="Marketing sections" className="mb-5 flex gap-1 border-b border-[var(--color-border)] pb-3">
            <Link to="/marketing" search={{}} className={BASE} activeProps={ACTIVE} activeOptions={{ exact: true }}>
                Campaigns
            </Link>
            <Link to="/marketing/segments" search={{}} className={BASE} activeProps={ACTIVE}>
                Segments
            </Link>
        </nav>
    );
}
