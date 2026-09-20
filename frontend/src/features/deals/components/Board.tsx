import { useMemo, useState } from 'react';
import { DndContext, type DragEndEvent, PointerSensor, useDroppable, useSensor, useSensors } from '@dnd-kit/core';
import { useQueryClient } from '@tanstack/react-query';
import { useDeals } from '../api/useDeals';
import { useMoveDealStage, isStaleDealError } from '../api/useMoveDealStage';
import { DEAL_STAGES, type Deal, type DealStage } from '../schemas/deal';
import { DealCard } from './DealCard';
import { isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';

function Column({ stage, label, deals }: { stage: DealStage; label: string; deals: Deal[] }) {
    const { setNodeRef, isOver } = useDroppable({ id: stage });

    return (
        <div
            ref={setNodeRef}
            className={`flex w-64 shrink-0 flex-col gap-2 rounded-lg border p-3 transition-colors ${
                isOver ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5' : 'border-[var(--color-border)] bg-[var(--color-paper)]'
            }`}
        >
            <div className="flex items-center justify-between px-1">
                <span className="text-sm font-medium text-[var(--color-ink)]">{label}</span>
                <span className="text-xs text-[var(--color-ink-muted)]">{deals.length}</span>
            </div>
            <div className="flex flex-col gap-2">
                {deals.map((deal) => (
                    <DealCard key={deal.id} deal={deal} />
                ))}
            </div>
        </div>
    );
}

export function DealsBoard() {
    const { data: deals, isPending, isError } = useDeals();
    const moveDealStage = useMoveDealStage();
    const [conflictMessage, setConflictMessage] = useState<string | null>(null);
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));

    const boardId = 1;
    const { tenant } = useTenant();
    const queryClient = useQueryClient();

    // 6.0/6.1: patches the query cache directly on an incoming broadcast —
    // no refetch, just the affected card's stage/lock_version updated in place.
    useEchoChannel(
        tenant ? `tenant.${tenant.id}.board.${boardId}` : '',
        'DealStageChanged',
        (payload) => {
            const { deal_id, to_stage, lock_version } = payload as {
                deal_id: string | number;
                to_stage: DealStage;
                lock_version: number;
            };

            queryClient.setQueryData<Deal[]>(['deals', tenant?.id, boardId], (old) =>
                old?.map((d) => (d.id === deal_id ? { ...d, stage: to_stage, lock_version } : d)) ?? [],
            );
        },
    );

    const dealsByStage = useMemo(() => {
        const grouped = new Map<DealStage, Deal[]>(DEAL_STAGES.map((s) => [s.value, []]));
        for (const deal of deals ?? []) {
            grouped.get(deal.stage)?.push(deal);
        }
        return grouped;
    }, [deals]);

    function handleDragEnd(event: DragEndEvent) {
        const { active, over } = event;
        if (!over) return;

        const deal = active.data.current?.deal as Deal | undefined;
        const toStage = over.id as DealStage;
        if (!deal || deal.stage === toStage) return;

        setConflictMessage(null);

        moveDealStage.mutate(
            { dealId: deal.id, toStage, lockVersion: deal.lock_version },
            {
                onError: (err) => {
                    if (isStaleDealError(err)) {
                        setConflictMessage('This deal was updated by someone else — refresh to see the latest stage.');
                    } else if (isApiError(err) && err.response?.status === 422) {
                        setConflictMessage(err.response.data.message);
                    } else {
                        setConflictMessage('Something went wrong moving that deal.');
                    }
                },
            },
        );
    }

    if (isPending) return <div className="text-sm text-[var(--color-ink-muted)]">Loading deals…</div>;
    if (isError) return <div className="text-sm text-[var(--color-danger)]">Couldn't load deals.</div>;

    return (
        <div className="flex flex-col gap-3">
            {conflictMessage && (
                <div className="rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 px-3 py-2 text-sm text-[var(--color-danger)]">
                    {conflictMessage}
                </div>
            )}
            <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
                <div className="flex gap-4 overflow-x-auto pb-2">
                    {DEAL_STAGES.map(({ value, label }) => (
                        <Column key={value} stage={value} label={label} deals={dealsByStage.get(value) ?? []} />
                    ))}
                </div>
            </DndContext>
        </div>
    );
}
