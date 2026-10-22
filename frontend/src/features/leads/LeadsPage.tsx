import { useState } from 'react';
import { Modal } from '@/shared/components/Modal';
import { LeadList } from './components/LeadList';
import { LeadForm } from './components/LeadForm';
import type { Lead } from './schemas/lead';


export function LeadsPage() {
    const [editingLead, setEditingLead] = useState<Lead | null>(null);
    const [isCreating, setIsCreating] = useState(false);
    const modalOpen = isCreating || !!editingLead;

    function closeModal() {
        setIsCreating(false);
        setEditingLead(null);
    }

    return (
        <div className="p-6">
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-semibold">Leads</h1>
                <button onClick={() => setIsCreating(true)} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">
                    New lead
                </button>
            </div>

            <LeadList onEdit={setEditingLead} />

            <Modal open={modalOpen} onClose={closeModal} title={editingLead ? 'Edit lead' : 'New lead'}>
                <LeadForm lead={editingLead ?? undefined} onSuccess={closeModal} />
            </Modal>
        </div>
    );
}
