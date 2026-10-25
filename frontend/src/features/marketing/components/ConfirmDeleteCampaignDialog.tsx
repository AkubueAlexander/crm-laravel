import { useEffect } from 'react';
import type { Campaign } from '../schemas/campaign';

type Props = {
    campaign: Campaign | null;
    isPending: boolean;
    error: string | null;
    onConfirm: () => void;
    onClose: () => void;
};

export function ConfirmDeleteCampaignDialog({ campaign, isPending, error, onConfirm, onClose }: Props) {
    useEffect(() => {
        if (!campaign) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !isPending) onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [campaign, isPending, onClose]);

    if (!campaign) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-campaign-title"
                className="w-full max-w-md rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"
            >
                <h2 id="delete-campaign-title" className="text-base font-medium text-[var(--color-ink)]">
                    Delete campaign?
                </h2>
                <p className="mt-2 text-sm text-[var(--color-ink-muted)]">
                    &ldquo;{campaign.name}&rdquo; will be permanently deleted. This cannot be undone.
                </p>

                {error && (
                    <p role="alert" className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {error}
                    </p>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={isPending}
                        className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={isPending}
                        className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {isPending ? 'Deleting...' : 'Delete'}
                    </button>
                </div>
            </div>
        </div>
    );
}
