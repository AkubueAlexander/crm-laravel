import { useState } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useInventoryItem, useInventoryMovements } from '../api/useInventory';
import { MOVEMENT_TYPES, MOVEMENT_TYPE_SHORT, formatQty, type MovementType } from '../schemas/inventory';
import { ItemFormModal } from './ItemFormModal';
import { MovementModal } from './MovementModal';

// 11.0c: item summary + read-only, append-only ledger (newest first). Movements are the only way stock changes.
export function ItemDetail() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const params = useParams({ strict: false }) as { itemId?: string };
    const itemId = Number(params.itemId);
    const validId = Number.isInteger(itemId) && itemId > 0;

    const [page, setPage] = useState(1);
    const [type, setType] = useState<MovementType | ''>('');
    const [moveOpen, setMoveOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    const item = useInventoryItem(validId && allowed ? itemId : null);
    const ledger = useInventoryMovements(validId && allowed ? itemId : null, { page, type: type || undefined });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to inventory.</p>;
    if (!validId) return <p className="text-sm text-red-700">Invalid item.</p>;
    if (item.isPending) return <p className="text-sm text-gray-500">Loading...</p>;
    if (item.isError || !item.data) {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {apiErrorMessage(item.error, "Couldn't load this item.")}
            </p>
        );
    }

    const it = item.data;
    const meta = ledger.data?.meta;
    const rows = ledger.data?.data ?? [];

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/inventory" className="text-[var(--color-ink-muted)] underline">
                    Inventory
                </Link>
            </div>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">
                    <span className="font-mono text-base">{it.sku}</span> {it.name}
                </h1>
                {canManage && (
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setEditOpen(true)} className="rounded-md border px-3 py-1.5 text-sm">
                            Edit
                        </button>
                        <button
                            type="button"
                            onClick={() => setMoveOpen(true)}
                            disabled={!it.is_active}
                            title={it.is_active ? undefined : 'Inactive items cannot take stock movements'}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            Record movement
                        </button>
                    </div>
                )}
            </div>

            <dl className="mb-6 grid max-w-xl grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                <div>
                    <dt className="text-[var(--color-ink-muted)]">On hand</dt>
                    <dd className="font-medium">
                        {formatQty(it.on_hand)} {it.unit}
                        {it.low_stock && <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Low stock</span>}
                    </dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Reorder at</dt>
                    <dd>{Number(it.reorder_level) > 0 ? formatQty(it.reorder_level) : '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Unit</dt>
                    <dd>{it.unit}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Status</dt>
                    <dd>{it.is_active ? 'Active' : 'Inactive'}</dd>
                </div>
            </dl>

            <section>
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-base font-medium text-[var(--color-ink)]">Stock ledger</h2>
                        <p className="text-xs text-[var(--color-ink-muted)]">Append-only: entries are never edited or deleted. Corrections are new adjustments.</p>
                    </div>
                    <select
                        aria-label="Filter by movement type"
                        value={type}
                        onChange={(e) => {
                            setType((e.target.value || '') as MovementType | '');
                            setPage(1);
                        }}
                        className="rounded-md border px-2 py-1.5 text-sm"
                    >
                        <option value="">All types</option>
                        {MOVEMENT_TYPES.map((t) => (
                            <option key={t} value={t}>
                                {MOVEMENT_TYPE_SHORT[t]}
                            </option>
                        ))}
                    </select>
                </div>

                {ledger.isError && (
                    <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {apiErrorMessage(ledger.error, "Couldn't load the ledger.")}
                    </p>
                )}

                <div className="overflow-x-auto rounded-md bg-gray-50">
                    <table className="w-full border-collapse text-sm">
                        <thead>
                            <tr>
                                <th className="border-b px-3 py-2 text-left font-medium">When</th>
                                <th className="border-b px-3 py-2 text-left font-medium">Type</th>
                                <th className="border-b px-3 py-2 text-right font-medium">Change</th>
                                <th className="border-b px-3 py-2 text-right font-medium">Balance</th>
                                <th className="border-b px-3 py-2 text-left font-medium">Reference / note</th>
                                <th className="border-b px-3 py-2 text-left font-medium">By</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((m) => {
                                const negative = m.quantity_delta.startsWith('-');
                                return (
                                    <tr key={m.id}>
                                        <td className="border-b px-3 py-2 align-top whitespace-nowrap">{m.created_at ? new Date(m.created_at).toLocaleString() : '-'}</td>
                                        <td className="border-b px-3 py-2 align-top">{MOVEMENT_TYPE_SHORT[m.type]}</td>
                                        <td className={`border-b px-3 py-2 text-right align-top font-mono ${negative ? 'text-red-700' : 'text-green-700'}`}>
                                            {negative ? '' : '+'}
                                            {formatQty(m.quantity_delta)}
                                        </td>
                                        <td className="border-b px-3 py-2 text-right align-top font-mono">{formatQty(m.balance_after)}</td>
                                        <td className="border-b px-3 py-2 align-top">
                                            <div>{m.reference ?? '-'}</div>
                                            {m.note && <div className="text-xs text-[var(--color-ink-muted)]">{m.note}</div>}
                                        </td>
                                        <td className="border-b px-3 py-2 align-top text-xs">{m.recorded_by ? `User #${m.recorded_by}` : '-'}</td>
                                    </tr>
                                );
                            })}
                            {!ledger.isPending && rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                        No movements yet.
                                    </td>
                                </tr>
                            )}
                            {ledger.isPending && (
                                <tr>
                                    <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                        Loading...
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {meta && (
                    <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
                        <span>
                            {meta.from ?? 0}-{meta.to ?? 0} of {meta.total}
                        </span>
                        <div className="flex gap-2">
                            <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-md border px-2 py-1 disabled:opacity-40">
                                Previous
                            </button>
                            <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)} className="rounded-md border px-2 py-1 disabled:opacity-40">
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </section>

            <MovementModal open={moveOpen} onClose={() => setMoveOpen(false)} item={it} />
            <ItemFormModal open={editOpen} onClose={() => setEditOpen(false)} item={it} />
        </div>
    );
}
