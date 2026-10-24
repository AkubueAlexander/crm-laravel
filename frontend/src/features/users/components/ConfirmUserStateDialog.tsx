import { Modal } from '@/shared/components/Modal';
import type { ManagedUser } from '../schemas/user';

export type UserStateMode = 'deactivate' | 'reactivate';

type Props = {
    user: ManagedUser | null;
    mode: UserStateMode;
    isPending: boolean;
    error: string | null;
    onConfirm: () => void;
    onClose: () => void;
};

export function ConfirmUserStateDialog({ user, mode, isPending, error, onConfirm, onClose }: Props) {
    const deactivating = mode === 'deactivate';

    return (
        <Modal open={user !== null} onClose={isPending ? () => undefined : onClose} title={deactivating ? 'Deactivate user' : 'Reactivate user'}>
            {user && (
                <div className="flex flex-col gap-4">
                    <p className="text-sm text-[var(--color-ink)]">
                        {deactivating ? (
                            <>
                                <strong>{user.name}</strong> ({user.email}) will be signed out and will no longer be able to
                                sign in. Their records stay in place and you can reactivate them at any time.
                            </>
                        ) : (
                            <>
                                <strong>{user.name}</strong> ({user.email}) will be able to sign in again with their existing
                                password and role.
                            </>
                        )}
                    </p>

                    {error && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {error}
                        </p>
                    )}

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={isPending}
                            className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={onConfirm}
                            disabled={isPending}
                            className={`rounded-md px-3 py-1.5 text-sm text-white disabled:opacity-50 ${
                                deactivating ? 'bg-red-600 hover:bg-red-700' : 'bg-[var(--color-accent)] hover:opacity-90'
                            }`}
                        >
                            {isPending ? 'Working...' : deactivating ? 'Deactivate' : 'Reactivate'}
                        </button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
