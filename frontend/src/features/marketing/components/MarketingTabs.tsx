import { Link } from '@tanstack/react-router';

const tabClass = 'border-b-2 border-transparent px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]';
const activeTabClass = 'border-b-2 border-[var(--color-ink)] px-3 py-2 text-sm font-medium text-[var(--color-ink)]';

/** Campaigns | Segments | Templates, rendered at the top of each marketing list page. */
export function MarketingTabs() {
  return (
    <nav aria-label="Marketing" className="mb-4 flex gap-1 border-b border-[var(--color-border)]">
      <Link
        to="/marketing"
        activeOptions={{ exact: true }}
        className={tabClass}
        activeProps={{ className: activeTabClass }}
      >
        Campaigns
      </Link>
      <Link
        to="/marketing/segments"
        activeOptions={{ exact: true }}
        className={tabClass}
        activeProps={{ className: activeTabClass }}
      >
        Segments
      </Link>
      <Link
        to="/marketing/templates"
        activeOptions={{ exact: true }}
        className={tabClass}
        activeProps={{ className: activeTabClass }}
      >
        Templates
      </Link>
    </nav>
  );
}