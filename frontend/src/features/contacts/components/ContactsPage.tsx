import { useState } from 'react';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { Timeline } from '@/features/activities/components/Timeline';
import { ContactList } from './ContactList';
import { ContactForm } from './ContactForm';
import { ContactMatchSettingsForm } from './ContactMatchSettingsForm';
import { ContactImportDialog } from './ContactImportDialog';
import { ContactImportToast } from './ContactImportToast';
import { useContactImportLive } from '../api/useContactImportLive';
import type { Contact } from '../schemas/contact';

type ModalState =
    | { mode: 'create' }
    | { mode: 'edit'; contact: Contact }
    | { mode: 'activity'; contact: Contact }
    | { mode: 'settings' }
    | { mode: 'import' }
    | null;

function contactLabel(contact: Contact): string {
    return [contact.first_name, contact.last_name].filter(Boolean).join(' ') || 'Contact';
}

function modalTitle(modal: ModalState): string {
    if (modal?.mode === 'edit') return 'Edit contact';
    if (modal?.mode === 'settings') return 'Contact settings';
    if (modal?.mode === 'import') return 'Import contacts';
    if (modal?.mode === 'activity') return `Activity - ${contactLabel(modal.contact)}`;
    return 'New contact';
}

export function ContactsPage() {
    const [modal, setModal] = useState<ModalState>(null);
    // UI-only: which import the dialog/toast follow. The import itself is server state (8.1).
    const [importId, setImportId] = useState<number | null>(null);
    // Display-only (2.3): the API re-checks activities.view and contacts.view.
    const canViewActivities = usePermission('activities.view');
    // Display-only (2.3): POST /contacts/imports re-checks contacts.create server-side and in the job.
    const canImport = usePermission('contacts.create');

    // Live progress for the import (8b.3), pushed over the per-user channel.
    useContactImportLive();

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Contacts</h1>
                <div className="flex items-center gap-2">
                    {canImport && (
                        <button
                            onClick={() => setModal({ mode: 'import' })}
                            className="rounded-md border px-3 py-1.5 text-sm font-medium text-[var(--color-ink)]"
                        >
                            Import CSV
                        </button>
                    )}
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

            {importId !== null && modal?.mode !== 'import' && (
                <ContactImportToast
                    importId={importId}
                    onOpen={() => setModal({ mode: 'import' })}
                    onDismiss={() => setImportId(null)}
                />
            )}

            <Modal open={modal !== null} onClose={() => setModal(null)} title={modalTitle(modal)}>
                {modal?.mode === 'settings' && <ContactMatchSettingsForm />}

                {modal?.mode === 'import' && (
                    <ContactImportDialog
                        importId={importId}
                        onStarted={setImportId}
                        onReset={() => setImportId(null)}
                        onClose={() => setModal(null)}
                    />
                )}

                {(modal?.mode === 'create' || modal?.mode === 'edit') && (
                    <ContactForm
                        contact={modal.mode === 'edit' ? modal.contact : undefined}
                        onSuccess={() => setModal(null)}
                    />
                )}

                {modal?.mode === 'edit' && canViewActivities && (
                    <div className="mt-6 border-t pt-4">
                        <button
                            type="button"
                            onClick={() => setModal({ mode: 'activity', contact: modal.contact })}
                            className="text-sm font-medium text-indigo-600 hover:underline"
                        >
                            View activity
                        </button>
                    </div>
                )}

                {modal?.mode === 'activity' && (
                    <div>
                        <button
                            type="button"
                            onClick={() => setModal({ mode: 'edit', contact: modal.contact })}
                            className="mb-3 text-sm text-indigo-600 hover:underline"
                        >
                            &larr; Back to contact
                        </button>
                        <div className="max-h-[60vh] overflow-y-auto pr-1">
                            <Timeline key={`contact-${modal.contact.id}`} targetType="contact" targetId={modal.contact.id} />
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
}
