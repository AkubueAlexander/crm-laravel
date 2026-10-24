import { useState } from 'react';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useInvitations } from '../api/useInvitations';
import { useResendInvitation, useRevokeInvitation } from '../api/useInvitationMutations';

export function PendingInvitations() {
    const { data, isError } = useInvitations();
    const resend = useResendInvitation();
    const revoke = useRevokeInvitation();
    const [confirmId, setConfirmId] = useState<number | null>(null);
    const [resentId, setResentId] = useState<number | null>(null);

    const rows = data ?? [];

    if (isError) return <p className="mt-6 text-sm text-red-600">Couldn't load pending invitations.</p>;
    if (rows.length === 0) return null;

    const resendingId = resend.isPending ? resend.variables : undefined;
    const revokingId = revoke.isPending ? revoke.variables : undefined;

    return (
        <section className="mt-8">
            <h2 className="mb-2 text-sm font-medium text-[var(--color-ink)]">Pending invitations</h2>

            {resend.isError && (
                <p role="alert" className="mb-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(resend.error, 'Could not resend the invitation.')}
                </p>
            )}
            {revoke.isError && (
                <p role="alert" className="mb-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(revoke.error, 'Could not revoke the invitation.')}
                </p>
            )}

            <table className="w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th className="border-b px-3 py-2 text-left font-medium">Email</th>
                        <th className="border-b px-3 py-2 text-left font-medium">Role</th>
                        <th className="border-b px-3 py-2 text-left font-medium">Invited by</th>
                        <th className="border-b px-3 py-2 text-left font-medium">Expires</th>
                        <th className="border-b px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((inv) => (
                        <tr key={inv.id} className={inv.expired ? 'text-gray-500' : ''}>
                            <td className="border-b px-3 py-2">{inv.email}</td>
                            <td className="border-b px-3 py-2">{inv.role_label}</td>
                            <td className="border-b px-3 py-2">{inv.invited_by_name ?? '\u2014'}</td>
                            <td className="border-b px-3 py-2">
                                {inv.expired ? (
                                    <span className="text-red-700">Expired</span>
                                ) : inv.expires_at ? (
                                    new Date(inv.expires_at).toLocaleDateString()
                                ) : (
                                    '\u2014'
                                )}
                            </td>
                            <td className="border-b px-3 py-2 text-right">
                                {confirmId === inv.id ? (
                                    <span className="inline-flex items-center gap-2">
                                        <span className="text-xs text-[var(--color-ink-muted)]">Revoke this invitation?</span>
                                        <button
                                            type="button"
                                            disabled={revokingId === inv.id}
                                            onClick={() => {
                                                revoke.mutate(inv.id, { onSuccess: () => setConfirmId(null) });
                                            }}
                                            className="rounded-md border px-2 py-1 text-xs text-red-700 disabled:opacity-50"
                                        >
                                            {revokingId === inv.id ? 'Revoking...' : 'Revoke'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setConfirmId(null)}
                                            className="rounded-md border px-2 py-1 text-xs"
                                        >
                                            Keep
                                        </button>
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-2">
                                        {resentId === inv.id && !inv.expired && (
                                            <span className="text-xs text-green-700">Sent again</span>
                                        )}
                                        <button
                                            type="button"
                                            disabled={resendingId === inv.id}
                                            onClick={() => {
                                                setResentId(null);
                                                resend.mutate(inv.id, { onSuccess: () => setResentId(inv.id) });
                                            }}
                                            className="rounded-md border px-2 py-1 text-xs disabled:opacity-50"
                                        >
                                            {resendingId === inv.id ? 'Sending...' : 'Resend'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                revoke.reset();
                                                setConfirmId(inv.id);
                                            }}
                                            className="rounded-md border px-2 py-1 text-xs text-red-700"
                                        >
                                            Revoke
                                        </button>
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}
