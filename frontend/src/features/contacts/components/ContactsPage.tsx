import { useState } from 'react';
import { Modal } from '@/shared/components/Modal';
import { ContactList } from './ContactList';
import { ContactForm } from './ContactForm';
import { ContactMatchSettingsForm } from './ContactMatchSettingsForm';
import type { Contact } from '../schemas/contact';

type ModalState =
    | { mode: 'create' }
    | { mode: 'edit'; contact: Contact }
    | { mode: 'settings' }
    | null;

// 5.C wiring: list + create/edit modal. No dedicated /contacts/:id detail route
// yet Ã¢â‚¬â€ matches the flat route structure already in router.tsx for Deals/Forecast.
export function ContactsPage() {
    const [modal, setModal] = useState<ModalState>(null);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Contacts</h1>
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => setModal({ mode: 'settings' })}
                        className="rounded-md border px-3 py-1.5 text-sm font-medium text-[var(--color-ink)]"
                    >
                        Settings
                    </button>
                    <button
                        onClick={() => setModal({ mode: 'create' })}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white"
                    >
                        New contact
                    </button>
                </div>
            </div>

            <ContactList onSelectContact={(contact) => setModal({ mode: 'edit', contact })} />

            <Modal
                open={modal !== null}
                onClose={() => setModal(null)}
                title={
                    modal?.mode === 'edit'
                        ? 'Edit contact'
                        : modal?.mode === 'settings'
                          ? 'Contact settings'
                          : 'New contact'
                }
            >
                {modal?.mode === 'settings' && <ContactMatchSettingsForm />}
                {(modal?.mode === 'create' || modal?.mode === 'edit') && (
                    <ContactForm
                        contact={modal.mode === 'edit' ? modal.contact : undefined}
                        onSuccess={() => setModal(null)}
                    />
                )}
            </Modal>
        </div>
    );
}