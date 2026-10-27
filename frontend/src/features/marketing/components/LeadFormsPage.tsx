import { useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { usePermission } from '@/shared/hooks/usePermission';
import { useLeadForms } from '../api/useLeadForms';
import { useDeleteLeadForm } from '../api/useDeleteLeadForm';
import { parseApiError } from '../api/leadFormErrors';
import type { LeadForm } from '../schemas/leadForm';
import { LeadFormBuilderDialog } from './LeadFormBuilderDialog';
import { publicUrl } from './LeadFormShareBox';

const columnHelper = createColumnHelper<LeadForm>();

// 9.8b: container owns the queries and "which dialog is open" (8.1/8.2). Display-only permission: the Actions
// re-check marketing.manage on the API (2.3).
export function LeadFormsPage() {
  const canManage = usePermission('marketing.manage');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<LeadForm | null>(null);
  const [creating, setCreating] = useState(false);
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const { data, isPending, isFetching, isError } = useLeadForms(page);
  const deleteForm = useDeleteLeadForm();

  async function copyLink(form: LeadForm) {
    try {
      await navigator.clipboard.writeText(publicUrl(form.public_path));
      setCopiedId(form.id);
      window.setTimeout(() => setCopiedId((current) => (current === form.id ? null : current)), 1500);
    } catch {
      setEditing(form); // the dialog shows the link in a selectable field
    }
  }

  function remove(form: LeadForm) {
    if (!confirm(`Delete the form "${form.name}"? Its public link will stop working. Leads it captured are kept.`)) return;
    setActionError(null);
    deleteForm.mutate(form.id, { onError: (error) => setActionError(parseApiError(error).message) });
  }

  const columns = useMemo(
    () => [
      columnHelper.accessor('name', { header: 'Name' }),
      columnHelper.accessor('is_active', {
        header: 'Status',
        cell: (i) => (
          <span className={i.getValue() ? 'text-green-700' : 'text-[var(--color-ink-muted)]'}>
            {i.getValue() ? 'Active' : 'Paused'}
          </span>
        ),
      }),
      columnHelper.accessor('source', { header: 'Source', cell: (i) => i.getValue() ?? '-' }),
      columnHelper.accessor((row) => row.fields.length, { id: 'fields', header: 'Fields' }),
      columnHelper.accessor('created_at', {
        header: 'Created',
        cell: (i) => (i.getValue() ? new Date(i.getValue()!).toLocaleDateString() : '-'),
      }),
      columnHelper.display({
        id: 'actions',
        header: '',
        cell: (info) => {
          const form = info.row.original;
          return (
            <div className="flex gap-3 text-sm">
              <button onClick={() => void copyLink(form)} className="text-gray-700 hover:underline">
                {copiedId === form.id ? 'Copied' : 'Copy link'}
              </button>
              {canManage && (
                <>
                  <button onClick={() => setEditing(form)} className="text-indigo-600 hover:underline">Edit</button>
                  <button onClick={() => remove(form)} className="text-red-600 hover:underline">Delete</button>
                </>
              )}
            </div>
          );
        },
      }),
    ],
    // copyLink/remove only use stable setters and the mutation object.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canManage, copiedId, deleteForm],
  );

  const table = useReactTable({
    data: data?.data ?? [],
    columns,
    manualPagination: true,
    getCoreRowModel: getCoreRowModel(),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold">Lead forms</h1>
          <p className="text-sm text-[var(--color-ink-muted)]">Capture leads from your website. Each submission becomes a new lead.</p>
        </div>
        {canManage && (
          <button onClick={() => setCreating(true)} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
            New form
          </button>
        )}
      </div>

      {actionError && <p role="alert" className="text-sm text-red-600">{actionError}</p>}
      {isError ? (
        <p className="text-sm text-red-600">Couldn't load lead forms. Try refreshing.</p>
      ) : (
        <>
          <table className="w-full text-left text-sm">
            <thead>
              {table.getHeaderGroups().map((hg) => (
                <tr key={hg.id} className="border-b">
                  {hg.headers.map((header) => (
                    <th key={header.id} className="py-2 pr-4 font-medium">
                      {flexRender(header.column.columnDef.header, header.getContext())}
                    </th>
                  ))}
                </tr>
              ))}
            </thead>
            <tbody>
              {isPending ? (
                <tr><td colSpan={columns.length} className="py-4 text-center text-[var(--color-ink-muted)]">Loading...</td></tr>
              ) : table.getRowModel().rows.length === 0 ? (
                <tr><td colSpan={columns.length} className="py-4 text-center text-[var(--color-ink-muted)]">No lead forms yet.</td></tr>
              ) : (
                table.getRowModel().rows.map((row) => (
                  <tr key={row.id} className="border-b last:border-0">
                    {row.getVisibleCells().map((cell) => (
                      <td key={cell.id} className="py-2 pr-4">{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>
                    ))}
                  </tr>
                ))
              )}
            </tbody>
          </table>

          {data?.meta && (
            <div className="flex items-center justify-between text-sm">
              <span className="text-[var(--color-ink-muted)]">
                {data.meta.total} form{data.meta.total === 1 ? '' : 's'}
                {isFetching ? ' - refreshing...' : ''}
              </span>
              <div className="flex gap-2">
                <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={data.meta.current_page <= 1} className="rounded-md border px-2 py-1 disabled:opacity-50">Previous</button>
                <button onClick={() => setPage((p) => p + 1)} disabled={data.meta.current_page >= data.meta.last_page} className="rounded-md border px-2 py-1 disabled:opacity-50">Next</button>
              </div>
            </div>
          )}
        </>
      )}

      <LeadFormBuilderDialog
        open={creating || editing !== null}
        leadForm={editing}
        onClose={() => {
          setCreating(false);
          setEditing(null);
        }}
      />
    </div>
  );
}