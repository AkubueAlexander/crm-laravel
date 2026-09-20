import { useDraggable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import type { Deal } from '../schemas/deal';

export function DealCard({ deal }: { deal: Deal }) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: deal.id,
        data: { deal },
    });

    const style = transform
        ? { transform: CSS.Translate.toString(transform), opacity: isDragging ? 0.5 : 1 }
        : undefined;

    return (
        <div
            ref={setNodeRef}
            style={style}
            {...listeners}
            {...attributes}
            className="cursor-grab rounded-md border border-[var(--color-border)] bg-white p-3 text-sm shadow-sm active:cursor-grabbing"
        >
            <div className="font-medium text-[var(--color-ink)]">{deal.name}</div>
            {deal.amount && (
                <div className="mt-1 text-xs text-[var(--color-ink-muted)]">
                    {new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number(deal.amount))}
                </div>
            )}
        </div>
    );
}
