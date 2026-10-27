import type { Lead } from '../schemas/lead';
import { LeadScoreBadge, scoreBand } from './LeadScoreBadge';

// 9.7: read-only factor breakdown (the score is computed server-side by LeadScoring).
export function LeadScoreBreakdown({ lead }: { lead: Lead }) {
  const band = scoreBand(lead.score);
  return (
    <div className="space-y-3 text-sm">
      <div className="flex items-center gap-3">
        <LeadScoreBadge score={lead.score} />
        <span className="font-medium">{lead.name}</span>
        <span className="text-[var(--color-ink-muted)]">{band.label} (0-100)</span>
      </div>

      {lead.score_factors.length === 0 ? (
        <p className="text-[var(--color-ink-muted)]">No scoring factors recorded yet.</p>
      ) : (
        <ul className="divide-y divide-[var(--color-border)] rounded-md border border-[var(--color-border)]">
          {lead.score_factors.map((f) => (
            <li key={f.key} className="flex items-center justify-between px-3 py-2">
              <span>{f.label}</span>
              <span
                className={`tabular-nums font-medium ${f.points < 0 ? 'text-red-600' : 'text-green-700'}`}
              >
                {f.points > 0 ? `+${f.points}` : f.points}
              </span>
            </li>
          ))}
        </ul>
      )}

      <p className="text-xs text-[var(--color-ink-muted)]">
        {lead.score_updated_at
          ? `Updated ${new Date(lead.score_updated_at).toLocaleString()}. `
          : 'Not scored yet. '}
        Scores are calculated automatically and cannot be edited.
      </p>
    </div>
  );
}