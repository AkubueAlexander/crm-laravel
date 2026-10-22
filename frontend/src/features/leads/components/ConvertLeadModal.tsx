import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { useConvertLead, getConvertFailure, type ConvertFailure } from '../api/useConvertLead';
import {
    convertLeadFormSchema,
    type ConvertLeadFormValues,
    type ConvertLeadPayload,
    type Lead,
} from '../schemas/lead';

type ConvertLeadModalProps = {
    lead: Lead | null; // non-null -> the modal is open for that lead
    onClose: () => void;
    // The lead has no last name (contacts require one): offer the edit form instead.
    onNeedsLastName?: (lead: Lead) => void;
};

// Mirrors ConvertLeadAction's default deal name so the placeholder is truthful.
function defaultDealName(lead: Lead): string {
    const company = lead.company?.trim();
    return company ? `${company} - ${lead.name}` : lead.name;
}

// 8.1: the open/closed state lives in the parent page; this only renders it.
export function ConvertLeadModal({ lead, onClose, onNeedsLastName }: ConvertLeadModalProps) {
    return (
        <Modal open={!!lead} onClose={onClose} title="Convert lead">
            {/* key resets all form state whenever a different lead is opened */}
            {lead && <ConvertLeadForm key={lead.id} lead={lead} onClose={onClose} onNeedsLastName={onNeedsLastName} />}
        </Modal>
    );
}

type ConvertLeadFormProps = {
    lead: Lead;
    onClose: () => void;
    onNeedsLastName?: (lead: Lead) => void;
};

// 8.3: every mutating form uses RHF + Zod, no exceptions.
function ConvertLeadForm({ lead, onClose, onNeedsLastName }: ConvertLeadFormProps) {
    const [failure, setFailure] = useState<ConvertFailure | null>(null);
    const [converted, setConverted] = useState<Lead | null>(null);

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
        setError,
    } = useForm<ConvertLeadFormValues>({
        resolver: zodResolver(convertLeadFormSchema),
        defaultValues: { deal_name: '', amount: '', expected_close_date: '' },
    });

    const convertLead = useConvertLead(lead.id);

    const hasLastName = !!lead.last_name?.trim();
    const company = lead.company?.trim();
    const alreadyConverted = failure?.code === 'already_converted';

    async function onSubmit(values: ConvertLeadFormValues) {
        setFailure(null);

        const payload: ConvertLeadPayload = {};
        const dealName = values.deal_name?.trim();
        const amount = values.amount?.trim();
        if (dealName) payload.deal_name = dealName;
        if (amount) payload.amount = amount;
        if (values.expected_close_date) payload.expected_close_date = values.expected_close_date;

        try {
            setConverted(await convertLead.mutateAsync(payload));
        } catch (err) {
            const result = getConvertFailure(err);
            // 14.3: field-level 422s land on the matching input.
            if (result.fieldErrors) {
                for (const [field, messages] of Object.entries(result.fieldErrors)) {
                    setError(field as keyof ConvertLeadFormValues, { message: messages[0] });
                }
            }
            setFailure(result);
        }
    }

    const isSaving = isSubmitting || convertLead.isPending;

    if (converted) {
        const created = ['a contact', converted.converted_account_id ? 'an account' : null, 'a deal']
            .filter(Boolean)
            .join(', ');

        return (
            <div className="space-y-4">
                <p className="text-sm">
                    <span className="font-medium">{lead.name}</span> was converted. Created: {created}.
                </p>
                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white"
                >
                    Done
                </button>
            </div>
        );
    }

    if (!hasLastName) {
        return (
            <div className="space-y-4">
                <p className="text-sm">
                    <span className="font-medium">{lead.name}</span> has no last name. Contacts require one, so add it
                    before converting.
                </p>
                <div className="flex gap-2">
                    {onNeedsLastName && (
                        <button
                            type="button"
                            onClick={() => onNeedsLastName(lead)}
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white"
                        >
                            Edit lead
                        </button>
                    )}
                    <button type="button" onClick={onClose} className="rounded-md border px-4 py-2 text-sm">
                        Cancel
                    </button>
                </div>
            </div>
        );
    }

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div className="rounded-md bg-[var(--color-paper)] p-3 text-sm">
                <p className="font-medium">Converting {lead.name} will create:</p>
                <ul className="mt-1 list-disc pl-5 text-[var(--color-ink-muted)]">
                    <li>A contact</li>
                    {company && <li>An account for {company} (reused if one with that name already exists)</li>}
                    <li>A deal</li>
                </ul>
            </div>

            <div>
                <label htmlFor="deal_name" className="block text-sm font-medium">Deal name</label>
                <input
                    id="deal_name"
                    placeholder={defaultDealName(lead)}
                    {...register('deal_name')}
                    className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                />
                {errors.deal_name && <p className="mt-1 text-sm text-red-600">{errors.deal_name.message}</p>}
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="amount" className="block text-sm font-medium">Amount</label>
                    <input
                        id="amount"
                        inputMode="decimal"
                        placeholder="0.00"
                        {...register('amount')}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.amount && <p className="mt-1 text-sm text-red-600">{errors.amount.message}</p>}
                </div>
                <div>
                    <label htmlFor="expected_close_date" className="block text-sm font-medium">Expected close date</label>
                    <input
                        id="expected_close_date"
                        type="date"
                        {...register('expected_close_date')}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.expected_close_date && (
                        <p className="mt-1 text-sm text-red-600">{errors.expected_close_date.message}</p>
                    )}
                </div>
            </div>

            {failure && !failure.fieldErrors && (
                <div className="space-y-2" role="alert">
                    <p className="text-sm text-red-600">{failure.message}</p>
                    {failure.code === 'missing_last_name' && onNeedsLastName && (
                        <button
                            type="button"
                            onClick={() => onNeedsLastName(lead)}
                            className="text-sm text-indigo-600 hover:underline"
                        >
                            Edit lead
                        </button>
                    )}
                </div>
            )}

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={isSaving || alreadyConverted}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    {isSaving ? 'Converting...' : 'Convert lead'}
                </button>
                <button type="button" onClick={onClose} className="rounded-md border px-4 py-2 text-sm">
                    {alreadyConverted ? 'Close' : 'Cancel'}
                </button>
            </div>
        </form>
    );
}
