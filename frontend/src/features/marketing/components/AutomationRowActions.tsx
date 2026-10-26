import { Link } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import type { Automation, AutomationStatus } from '../schemas/automation';

const BTN = 'rounded-md border px-2 py-1 text-xs disabled:opacity-40';

/** "New automation" button for the list header. Rendered only for marketing.manage. */
export function NewAutomationButton() {
    const canManage = usePermission('marketing.manage');
    if (!canManage) return null;
    return (
        <Link
            to="/marketing/automations/new"
            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm font-medium text-[var(--color-paper)]"
        >
            New automation
        </Link>
    );
}

type Props = {
    automation: Automation;
    busy: boolean;
    onTransition: (to: AutomationStatus) => void;
    onDelete: () => void;
};

// Which actions exist per status mirrors TransitionAutomationAction::TRANSITIONS (9.6a-2); the API re-checks all of it.
// A hidden button is not a security boundary (2.3).
export function AutomationRowActions({ automation: a, busy, onTransition, onDelete }: Props) {
    const canManage = usePermission('marketing.manage');

    return (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <Link to="/marketing/automations/$automationId" params={{ automationId: a.id }} className="text-sm underline">
                {canManage ? 'Edit' : 'View'}
            </Link>

            {canManage && a.status === 'draft' && (
                <button type="button" disabled={busy} onClick={() => onTransition('active')} className={BTN}>
                    Activate
                </button>
            )}
            {canManage && a.status === 'active' && (
                <button type="button" disabled={busy} onClick={() => onTransition('paused')} className={BTN}>
                    Pause
                </button>
            )}
            {canManage && a.status === 'paused' && (
                <>
                    <button type="button" disabled={busy} onClick={() => onTransition('active')} className={BTN}>
                        Resume
                    </button>
                    <button type="button" disabled={busy} onClick={() => onTransition('draft')} className={BTN}>
                        Back to draft
                    </button>
                </>
            )}
            {canManage && (
                <button
                    type="button"
                    disabled={busy || a.status === 'active'}
                    title={a.status === 'active' ? 'Pause this automation before deleting it.' : undefined}
                    onClick={onDelete}
                    className={`${BTN} text-red-700`}
                >
                    Delete
                </button>
            )}
        </div>
    );
}