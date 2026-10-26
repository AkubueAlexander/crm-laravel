import type { Campaign } from '../schemas/campaign';
import type { LifecycleKind } from '../schemas/campaignForm';

type Props = {
    campaign: Campaign;
    busy: boolean;
    onEdit: () => void;
    onDelete: () => void;
    onLifecycle: (kind: LifecycleKind) => void;
    onUnschedule: () => void;
};

const BTN = 'rounded-md border px-2 py-1 text-xs disabled:opacity-40';

// Which actions exist per status mirrors TransitionCampaignAction::TRANSITIONS (9.4a); the API re-checks all of it.
// A hidden button is not a security boundary (2.3): the caller only renders this for marketing.manage.
export function CampaignRowActions({ campaign: c, busy, onEdit, onDelete, onLifecycle, onUnschedule }: Props) {
    const hasAudience = c.segment_id !== null && c.email_template_id !== null;
    const audienceHint = hasAudience ? undefined : 'Edit the campaign and choose a segment and a template first.';

    switch (c.status) {
        case 'draft':
            return (
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={onEdit} disabled={busy} className={BTN}>
                        Edit
                    </button>
                    <button
                        type="button"
                        onClick={() => onLifecycle('send')}
                        disabled={busy || !hasAudience}
                        title={audienceHint}
                        className={BTN}
                    >
                        Send now
                    </button>
                    <button
                        type="button"
                        onClick={() => onLifecycle('schedule')}
                        disabled={busy || !hasAudience}
                        title={audienceHint}
                        className={BTN}
                    >
                        Schedule
                    </button>
                    <button type="button" onClick={onDelete} disabled={busy} className={`${BTN} text-red-700`}>
                        Delete
                    </button>
                </div>
            );
        case 'scheduled':
            return (
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={onUnschedule} disabled={busy} className={BTN}>
                        Unschedule
                    </button>
                    <button type="button" onClick={() => onLifecycle('cancel')} disabled={busy} className={`${BTN} text-red-700`}>
                        Cancel
                    </button>
                </div>
            );
        case 'sending':
            return (
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => onLifecycle('cancel')} disabled={busy} className={`${BTN} text-red-700`}>
                        Cancel
                    </button>
                </div>
            );
        case 'failed':
            return (
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => onLifecycle('retry')} disabled={busy} className={BTN}>
                        Retry send
                    </button>
                    <button type="button" onClick={() => onLifecycle('cancel')} disabled={busy} className={`${BTN} text-red-700`}>
                        Cancel
                    </button>
                </div>
            );
        default:
            // sent and cancelled are terminal.
            return null;
    }
}