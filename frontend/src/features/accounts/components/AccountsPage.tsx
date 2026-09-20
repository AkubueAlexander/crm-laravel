import { useState } from 'react';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { isApiError } from '@/shared/lib/apiClient';
import { AccountList } from './AccountList';
import { AccountForm } from './AccountForm';
import { useDeleteAccount } from '../api/useAccountMutations';
import type { Account } from '../schemas/account';

type ModalState = { mode: 'create' } | { mode: 'edit'; account: Account } | null;

// 8a.1 wiring: list + create/edit modal. Buttons and row-click are gated by the
// same permissions the API enforces; the API remains the real boundary (2.3).
export function AccountsPage() {
    const canCreate = usePermission('accounts.create');
    const canUpdate = usePermission('accounts.update');
    const canDelete = usePermission('accounts.delete');

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
                onSelectAccount={canUpdate ? (account) => openModal({ mode: 'edit', account }) : undefined}
            />

            <Modal
                open={modal !== null}
                onClose={() => setModal(null)}
                title={modal?.mode === 'edit' ? 'Edit account' : 'New account'}
            >
                {modal && (
                    <AccountForm
                        account={modal.mode === 'edit' ? modal.account : undefined}
                        onSuccess={() => setModal(null)}
                    />
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
            </Modal>
        </div>
    );
}