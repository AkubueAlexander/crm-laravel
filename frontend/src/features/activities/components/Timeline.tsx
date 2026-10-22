import { useState } from 'react';
import { isApiError } from '@/shared/lib/apiClient';
import { usePermission } from '@/shared/hooks/usePermission';
import { useActivities } from '../api/useActivities';
import { useCompleteActivity, useDeleteActivity } from '../api/useActivityMutations';
import type { ActivityTargetType } from '../schemas/activity';
import { ActivityForm } from './ActivityForm';

type TimelineProps = {
    targetType: ActivityTargetType;
    targetId: number;
};

type StatusFilter = '' | 'open' | 'completed';

function failureMessage(err: unknown): string {
    return isApiError(err) ? err.response?.data.message ?? 'Request failed.' : 'Something went wrong. Try again.';
}

// 8a.3: one parameterized feed, reused on Lead / Contact / Account / Deal views.
// Parent should pass key={`${targetType}-${targetId}`} so local page state resets.
export function Timeline({ targetType, targetId }: TimelineProps) {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState<StatusFilter>('');
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    // Display-only (2.3): every action is re-authorized server-side.
    const canCreate = usePermission('activities.create');
    const canUpdate = usePermission('activities.update');
    const canComplete = usePermission('activities.complete');
    const canDelete = usePermission('activities.delete');

    const { data, isPending, isFetching, isError } = useActivities({
        targetType,
        targetId,
        status: status || undefined,
        page,
    });
    const complete = useCompleteActivity();
    const remove = useDeleteActivity();
    const actionError = complete.error ?? remove.error;

    const target = { type: targetType, id: targetId };

    function handleDelete(id: number, subject: string) {
        if (!confirm(`Delete activity "${subject}"?`)) return;
        remove.mutate(id, {
            onSuccess: () => {
                // Deleting the last row of a later page would leave an empty page.
                if ((data?.data.length ?? 0) <= 1) setPage((p) => Math.max(1, p - 1));
            },
        });
    }

    return (
        <div>
            <div className="mb-3 flex items-center gap-2">
                <label htmlFor="activity-status" className="text-sm font-medium">Show</label>
                <select
                    id="activity-status"
                    value={status}
                    onChange={(e) => { setStatus(e.target.value as StatusFilter); setPage(1); }}
                    className="rounded-md border px-2 py-1 text-sm"
                >
                    <option value="">All</option>
                    <option value="open">Open</option>
                    <option value="completed">Completed</option>
                </select>
                {isFetching && <span className="text-xs text-[var(--color-ink-muted)]">Refreshing...</span>}
                {canCreate && !adding && (
                    <button onClick={() => { setAdding(true); setEditingId(null); }} className="ml-auto rounded-md bg-indigo-600 px-3 py-1 text-sm font-medium text-white">
                        Add activity
                    </button>
                )}
            </div>

            {adding && canCreate && (
                <div className="mb-3">
                    <ActivityForm
                        target={target}
                        onSuccess={() => { setAdding(false); setPage(1); }}
                        onCancel={() => setAdding(false)}
                    />
                </div>
            )}

            {actionError && <p className="mb-2 text-sm text-red-600">{failureMessage(actionError)}</p>}

            {isError ? (
                <p className="text-sm text-red-600">Couldn't load activities. Try again.</p>
            ) : isPending ? (
                <p className="py-4 text-center text-sm text-[var(--color-ink-muted)]">Loading...</p>
            ) : data.data.length === 0 ? (
                <p className="py-4 text-center text-sm text-[var(--color-ink-muted)]">No activities yet.</p>
            ) : (
                <ul className="divide-y divide-[var(--color-border)]">
                    {data.data.map((a) => (
                        <li key={a.id} className="py-3">
                            {editingId === a.id ? (
                                <ActivityForm
                                    target={target}
                                    activity={a}
                                    onSuccess={() => setEditingId(null)}
                                    onCancel={() => setEditingId(null)}
                                />
                            ) : (
                                <div className="flex gap-3">
                                    <input
                                        type="checkbox"
                                        checked={a.is_completed}
                                        disabled={!canComplete || complete.isPending}
                                        onChange={() => complete.mutate({ id: a.id, completed: !a.is_completed })}
                                        aria-label={a.is_completed ? `Reopen ${a.subject}` : `Complete ${a.subject}`}
                                        className="mt-1"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-[var(--color-ink-muted)]">
                                            <span className="rounded bg-[var(--color-paper)] px-1.5 py-0.5 capitalize">{a.type}</span>
                                            {a.due_at && <span>Due {new Date(a.due_at).toLocaleString()}</span>}
                                            {a.completed_at && <span>Done {new Date(a.completed_at).toLocaleDateString()}</span>}
                                        </div>
                                        <p className={`text-sm font-medium ${a.is_completed ? 'text-[var(--color-ink-muted)] line-through' : ''}`}>
                                            {a.subject}
                                        </p>
                                        {a.body && <p className="whitespace-pre-wrap text-sm text-[var(--color-ink-muted)]">{a.body}</p>}
                                        <p className="mt-1 text-xs text-[var(--color-ink-muted)]">
                                            {a.owner?.name ?? 'Unassigned'}
                                            {a.created_at ? ` - added ${new Date(a.created_at).toLocaleDateString()}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex gap-2 text-sm">
                                        {canUpdate && (
                                            <button onClick={() => { setEditingId(a.id); setAdding(false); }} className="text-indigo-600 hover:underline">
                                                Edit
                                            </button>
                                        )}
                                        {canDelete && (
                                            <button onClick={() => handleDelete(a.id, a.subject)} disabled={remove.isPending} className="text-red-600 hover:underline disabled:opacity-50">
                                                Delete
                                            </button>
                                        )}
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {data?.meta && data.meta.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-sm">
                    <span className="text-[var(--color-ink-muted)]">
                        {data.meta.total} activit{data.meta.total === 1 ? 'y' : 'ies'}
                    </span>
                    <div className="flex gap-2">
                        <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={data.meta.current_page <= 1} className="rounded-md border px-2 py-1 disabled:opacity-50">
                            Previous
                        </button>
                        <button onClick={() => setPage((p) => p + 1)} disabled={data.meta.current_page >= data.meta.last_page} className="rounded-md border px-2 py-1 disabled:opacity-50">
                            Next
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
