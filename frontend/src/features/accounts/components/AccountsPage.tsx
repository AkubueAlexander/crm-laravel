import { useState } from 'react';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { isApiError } from '@/shared/lib/apiClient';
import { Timeline } from '@/features/activities/components/Timeline';
import { AccountList } from './AccountList';
import { AccountForm } from './AccountForm';
import { useDeleteAccount } from '../api/useAccountMutations';
import type { Account } from '../schemas/account';

type ModalState =
    | { mode: 'create' }
    | { mode: 'edit'; account: Account }
    | { mode: 'activity'; account: Account }
    | null;

function modalTitle(modal: ModalState): string {
    if (modal?.mode === 'edit') return 'Edit account';
    if (modal?.mode === 'activity') return `Activity - ${modal.account.name}`;
    return 'New account';
}

export function AccountsPage() {
    const canCreate = usePermission('accounts.create');
    const canUpdate = usePermission('accounts.update');
    const canDelete = usePermission('accounts.delete');
    // Display-only (2.3): the API re-checks activities.view and accounts.view.
    const canViewActivities = usePermission('activities.view');

    const [modal, setModal] = useState<ModalState>(null);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const deleteAccount = useDeleteAccount();

    function openModal(next: ModalState) {
        setDeleteError(null);
        setModal(next);
    }

    async function handleDelete(account: Account) {
        if (!window.confirm(`Delete "${account.name}"? Its contacts are not deleted.`)) return;

        setDeleteError(null);
        try {
            await deleteAccount.mutateAsync(account.id);
            setModal(null);
        } catch (err) {
            setDeleteError(
                isApiError(err) ? err.response?.data.message ?? "Couldn't delete account." : "Couldn't delete account.",
            );
        }
    }

    // Rows are clickable for anyone who can edit OR see activity. A view-only user
    // goes straight to the timeline and never sees the edit form.
    const canOpenRow = canUpdate || canViewActivities;

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Accounts</h1>
                {canCreate && (
                    <button
                        onClick={() => openModal({ mode: 'create' })}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white"
                    >
                        New account
                    </button>
                )}
            </div>

            <AccountList
                onSelectAccount={
                    canOpenRow
                        ? (account) => openModal({ mode: canUpdate ? 'edit' : 'activity', account })
                        : undefined
                }
            />

            <Modal open={modal !== null} onClose={() => setModal(null)} title={modalTitle(modal)}>
                {(modal?.mode === 'create' || modal?.mode === 'edit') && (
                    <AccountForm
                        account={modal.mode === 'edit' ? modal.account : undefined}
                        onSuccess={() => setModal(null)}
                    />
                )}

                {modal?.mode === 'edit' && canViewActivities && (
                    <div className="mt-6 border-t pt-4">
                        <button
                            type="button"
                            onClick={() => setModal({ mode: 'activity', account: modal.account })}
                            className="text-sm font-medium text-indigo-600 hover:underline"
                        >
                            View activity
                        </button>
                    </div>
                )}

                {modal?.mode === 'edit' && canDelete && (
                    <div className="mt-6 border-t pt-4">
                        {deleteError && <p className="mb-2 text-sm text-red-600">{deleteError}</p>}
                        <button
                            type="button"
                            onClick={() => handleDelete(modal.account)}
                            disabled={deleteAccount.isPending}
                            className="text-sm font-medium text-red-600 disabled:opacity-50"
                        >
                            {deleteAccount.isPending ? 'Deleting\u2026' : 'Delete account'}
                        </button>
                    </div>
                )}

                {modal?.mode === 'activity' && (
                    <div>
                        {canUpdate && (
                            <button
                                type="button"
                                onClick={() => setModal({ mode: 'edit', account: modal.account })}
                                className="mb-3 text-sm text-indigo-600 hover:underline"
                            >
                                &larr; Back to account
                            </button>
                        )}
                        <div className="max-h-[60vh] overflow-y-auto pr-1">
                            <Timeline key={`account-${modal.account.id}`} targetType="account" targetId={modal.account.id} />
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
}
