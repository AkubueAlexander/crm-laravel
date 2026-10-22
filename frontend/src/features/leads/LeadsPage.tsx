import { useState } from 'react';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { LeadList } from './components/LeadList';
import { LeadForm } from './components/LeadForm';
import { ConvertLeadModal } from './components/ConvertLeadModal';
import type { Lead } from './schemas/lead';

// 8.2: container owns "which modal is open" (8.1: ephemeral visual state ->
// local useState, not Zustand - it doesn't need to survive a route change).
export function LeadsPage() {
    const [editingLead, setEditingLead] = useState<Lead | null>(null);
    const [convertingLead, setConvertingLead] = useState<Lead | null>(null);
    const [isCreating, setIsCreating] = useState(false);
    // Display-only (2.3): hides the button. ConvertLeadAction re-checks leads.convert server-side.
    const canConvert = usePermission('leads.convert');
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

            <LeadList onEdit={setEditingLead} onConvert={canConvert ? setConvertingLead : undefined} />

            <Modal open={modalOpen} onClose={closeModal} title={editingLead ? 'Edit lead' : 'New lead'}>
                <LeadForm lead={editingLead ?? undefined} onSuccess={closeModal} />
            </Modal>

            <ConvertLeadModal
                lead={convertingLead}
                onClose={() => setConvertingLead(null)}
                onNeedsLastName={(lead) => {
                    setConvertingLead(null);
                    setEditingLead(lead);
                }}
            />
        </div>
    );
}
