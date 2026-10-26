type Props = {
    recipients: number;
    sent: number;
    failed: number;
};

// Processed = sent + failed, out of the frozen recipient snapshot (9.4b).
export function CampaignProgressBar({ recipients, sent, failed }: Props) {
    const done = sent + failed;
    const percent = recipients > 0 ? Math.min(100, Math.round((done / recipients) * 100)) : 0;

    return (
        <div className="min-w-[10rem]">
            <div
                role="progressbar"
                aria-label="Send progress"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                className="h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-border)]"
            >
                <div className="h-full bg-[var(--color-ink)] transition-all" style={{ width: `${percent}%` }} />
            </div>
            <p className="mt-1 text-xs text-[var(--color-ink-muted)]">
                {done} of {recipients} processed{failed > 0 ? ` (${failed} failed)` : ''}
            </p>
        </div>
    );
}