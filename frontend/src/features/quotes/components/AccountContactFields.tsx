import { useState } from 'react';
import { useController, type Control } from 'react-hook-form';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { useAccountOptions } from '@/features/accounts/api/useAccountOptions';
import { useContact } from '@/features/contacts/api/useContact';
import { useContacts } from '@/features/contacts/api/useContacts';
import type { QuoteFormValues } from '../schemas/quoteForm';

const INPUT = 'w-full rounded-md border px-3 py-2 text-sm';

type Option = { id: number; label: string };

function toId(value: string): number | null {
    if (value === '') return null;
    const n = Number(value);
    return Number.isInteger(n) && n > 0 ? n : null;
}

// 10.1: optional Account and Contact for a quote. Both lists come from TanStack Query (tenant-namespaced keys
// live in the hooks). The form only holds the selected ids; the server re-validates ownership (1.3, 14.2).
export function AccountContactFields({ control }: { control: Control<QuoteFormValues> }) {
    const account = useController({ control, name: 'account_id' });
    const contact = useController({ control, name: 'contact_id' });
    const accountId = account.field.value;
    const contactId = contact.field.value;

    const [search, setSearch] = useState('');
    const q = useDebouncedValue(search, 300).trim();

    const accounts = useAccountOptions();
    const contacts = useContacts({ q, account_id: accountId ?? undefined, perPage: 20 });
    const selectedContact = useContact(contactId ?? undefined);

    const accountOptions: Option[] = (accounts.data ?? []).map((a) => ({ id: a.id, label: a.name }));
    if (accountId !== null && !accountOptions.some((o) => o.id === accountId)) {
        accountOptions.unshift({ id: accountId, label: accounts.isPending ? 'Loading...' : `Account #${accountId}` });
    }

    const contactOptions: Option[] = (contacts.data?.data ?? []).map((c) => ({
        id: c.id,
        label: c.email ? `${c.full_name} (${c.email})` : c.full_name,
    }));
    if (contactId !== null && !contactOptions.some((o) => o.id === contactId)) {
        contactOptions.unshift({ id: contactId, label: selectedContact.data?.full_name ?? `Contact #${contactId}` });
    }

    function onAccountChange(next: number | null) {
        account.field.onChange(next);
        // A contact picked under the previous account may not belong to the new one.
        if (contactId !== null && next !== accountId) contact.field.onChange(null);
        setSearch('');
    }

    return (
        <div className="grid grid-cols-2 gap-3">
            <div>
                <label htmlFor="quote-account" className="mb-1 block text-sm font-medium">
                    Account <span className="font-normal text-[var(--color-ink-muted)]">(optional)</span>
                </label>
                <select
                    id="quote-account"
                    className={INPUT}
                    ref={account.field.ref}
                    onBlur={account.field.onBlur}
                    value={accountId ?? ''}
                    onChange={(e) => onAccountChange(toId(e.target.value))}
                >
                    <option value="">No account</option>
                    {accountOptions.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.label}
                        </option>
                    ))}
                </select>
                {account.fieldState.error?.message && (
                    <p role="alert" className="mt-1 text-xs text-red-700">
                        {account.fieldState.error.message}
                    </p>
                )}
            </div>

            <div>
                <label htmlFor="quote-contact" className="mb-1 block text-sm font-medium">
                    Contact <span className="font-normal text-[var(--color-ink-muted)]">(optional)</span>
                </label>
                <input
                    type="search"
                    aria-label="Search contacts"
                    placeholder={accountId !== null ? 'Search contacts of this account...' : 'Search contacts...'}
                    autoComplete="off"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className={`${INPUT} mb-1`}
                />
                <select
                    id="quote-contact"
                    className={INPUT}
                    ref={contact.field.ref}
                    onBlur={contact.field.onBlur}
                    value={contactId ?? ''}
                    onChange={(e) => contact.field.onChange(toId(e.target.value))}
                >
                    <option value="">No contact</option>
                    {contactOptions.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.label}
                        </option>
                    ))}
                </select>
                {contacts.isError && (
                    <p role="alert" className="mt-1 text-xs text-red-700">
                        Could not load contacts.
                    </p>
                )}
                {contact.fieldState.error?.message && (
                    <p role="alert" className="mt-1 text-xs text-red-700">
                        {contact.fieldState.error.message}
                    </p>
                )}
            </div>
        </div>
    );
}