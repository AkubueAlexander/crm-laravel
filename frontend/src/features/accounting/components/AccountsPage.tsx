import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { useForm, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useLedgerAccounts } from '../api/useAccounting';
import { accountingFieldErrors, useLoadDefaultChart, useSaveLedgerAccount } from '../api/useAccountingMutations';
import { ACCOUNT_TYPES, ACCOUNT_TYPE_LABELS, accountFormSchema, type AccountFormValues, type AccountType, type LedgerAccount } from '../schemas/accounting';

const columnHelper = createColumnHelper<LedgerAccount>();
const EMPTY: LedgerAccount[] = [];
const FORM_FIELDS = ['code', 'name', 'type', 'is_active'] as const;

function AccountFormModal({ open, onClose, account }: { open: boolean; onClose: () => void; account: LedgerAccount | null }) {
    const save = useSaveLedgerAccount();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<AccountFormValues>({
        resolver: zodResolver(accountFormSchema),
        defaultValues: { code: '', name: '', type: 'asset', is_active: true },
    });

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset(
                account
                    ? { code: account.code, name: account.name, type: account.type, is_active: account.is_active }
                    : { code: '', name: '', type: 'asset', is_active: true },
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, account]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await save.mutateAsync({ accountId: account?.id, values });
            onClose();
        } catch (err) {
            const fields422 = accountingFieldErrors(err);
            let shown = false;
            for (const [key, message] of Object.entries(fields422)) {
                if ((FORM_FIELDS as readonly string[]).includes(key)) {
                    form.setError(key as Path<AccountFormValues>, { message });
                    shown = true;
                }
            }
            if (!shown) setFormError(apiErrorMessage(err, 'Could not save the account. Please try again.'));
        }
    });

    const errors = form.formState.errors;
    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';

    return (
        <Modal open={open} onClose={onClose} title={account ? `Edit ${account.code}` : 'New account'}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <div>
                    <label htmlFor="acct-code" className="mb-1 block text-sm font-medium">
                        Code
                    </label>
                    <input id="acct-code" aria-invalid={errors.code ? true : undefined} className={inputClass} {...form.register('code')} />
                    {errors.code?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {errors.code.message}
                        </p>
                    )}
                </div>
                <div>
                    <label htmlFor="acct-name" className="mb-1 block text-sm font-medium">
                        Name
                    </label>
                    <input id="acct-name" aria-invalid={errors.name ? true : undefined} className={inputClass} {...form.register('name')} />
                    {errors.name?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {errors.name.message}
                        </p>
                    )}
                </div>
                <div>
                    <label htmlFor="acct-type" className="mb-1 block text-sm font-medium">
                        Type
                    </label>
                    <select id="acct-type" aria-invalid={errors.type ? true : undefined} className={inputClass} {...form.register('type')}>
                        {ACCOUNT_TYPES.map((t) => (
                            <option key={t} value={t}>
                                {ACCOUNT_TYPE_LABELS[t]}
                            </option>
                        ))}
                    </select>
                    {errors.type?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {errors.type.message}
                        </p>
                    )}
                    {account && <p className="mt-1 text-xs text-[var(--color-ink-muted)]">The code and type are locked once an account has postings.</p>}
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" {...form.register('is_active')} />
                    Active (inactive accounts cannot be posted to)
                </label>
                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} disabled={form.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Cancel
                    </button>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Saving...' : 'Save account'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

// 12.1a: chart of accounts. Small list, so the type filter is client-side.
export function LedgerAccountsPage() {
    const allowed = usePermission('accounting.view');
    const canManage = usePermission('accounting.manage');
    const [typeFilter, setTypeFilter] = useState<'' | AccountType>('');
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<LedgerAccount | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    const accounts = useLedgerAccounts(allowed);
    const loadDefaults = useLoadDefaultChart();

    const rows = useMemo(() => {
        const all = accounts.data ?? EMPTY;
        return all.filter((a) => !typeFilter || a.type === typeFilter).sort((a, b) => a.code.localeCompare(b.code));
    }, [accounts.data, typeFilter]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('code', { header: 'Code', cell: (info) => <span className="font-mono text-xs">{info.getValue()}</span> }),
            columnHelper.accessor('name', { header: 'Name', cell: (info) => <span className="font-medium">{info.getValue()}</span> }),
            columnHelper.accessor('type', { header: 'Type', cell: (info) => ACCOUNT_TYPE_LABELS[info.getValue()] }),
            columnHelper.accessor('is_active', {
                header: 'Status',
                cell: (info) => (
                    <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{info.getValue() ? 'Active' : 'Inactive'}</span>
                ),
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) =>
                    canManage ? (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(row.original);
                                setModalOpen(true);
                            }}
                            className="rounded-md border px-2 py-1 text-xs"
                        >
                            Edit
                        </button>
                    ) : null,
            }),
        ],
        [canManage],
    );

    const table = useReactTable({ data: rows, columns, getCoreRowModel: getCoreRowModel() });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;

    const total = accounts.data?.length ?? 0;

    async function onLoadDefaults() {
        setNotice(null);
        setLoadError(null);
        try {
            const created = await loadDefaults.mutateAsync();
            setNotice(created > 0 ? `Added ${created} starter accounts.` : 'The starter accounts already exist.');
        } catch (err) {
            setLoadError(apiErrorMessage(err, 'Could not load the default chart.'));
        }
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Chart of accounts</h1>
                    <Link to="/accounting" className="text-sm underline">
                        Journal
                    </Link>
                </div>
                {canManage && (
                    <div className="flex items-center gap-2">
                        {total > 0 && (
                            <button type="button" onClick={() => void onLoadDefaults()} disabled={loadDefaults.isPending} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                                Add missing starter accounts
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setModalOpen(true);
                            }}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90"
                        >
                            New account
                        </button>
                    </div>
                )}
            </div>

            <div className="mb-3 flex items-center gap-2">
                <select aria-label="Filter by type" value={typeFilter} onChange={(e) => setTypeFilter(e.target.value as '' | AccountType)} className="rounded-md border px-2 py-2 text-sm">
                    <option value="">All types</option>
                    {ACCOUNT_TYPES.map((t) => (
                        <option key={t} value={t}>
                            {ACCOUNT_TYPE_LABELS[t]}
                        </option>
                    ))}
                </select>
            </div>

            {notice && <p className="mb-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">{notice}</p>}
            {loadError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {loadError}
                </p>
            )}
            {accounts.isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(accounts.error, "Couldn't load the chart of accounts.")}
                </p>
            )}

            {accounts.isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : total === 0 ? (
                <div className="rounded-md border border-dashed p-6 text-center text-sm text-[var(--color-ink-muted)]">
                    <p className="mb-3">No accounts yet.</p>
                    {canManage && (
                        <button type="button" onClick={() => void onLoadDefaults()} disabled={loadDefaults.isPending} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                            {loadDefaults.isPending ? 'Loading...' : 'Load default chart'}
                        </button>
                    )}
                </div>
            ) : (
                <table className="w-full border-collapse text-sm">
                    <thead>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <th key={header.id} className="border-b px-3 py-2 text-left font-medium">
                                        {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                    </th>
                                ))}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.map((row) => (
                            <tr key={row.original.id}>
                                {row.getVisibleCells().map((cell) => (
                                    <td key={cell.id} className="border-b px-3 py-2 align-top">
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-3 py-6 text-center text-gray-500">
                                    No accounts of this type.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}

            <AccountFormModal open={modalOpen} onClose={() => setModalOpen(false)} account={editing} />
        </div>
    );
}
