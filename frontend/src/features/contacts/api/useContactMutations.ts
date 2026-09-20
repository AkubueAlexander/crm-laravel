import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import type { Contact, CreateContactInput, UpdateContactInput } from '../schemas/contact';
import { contactSchema } from '../schemas/contact';

/**
 * Contacts are last-write-wins, not a state-machine (per running convention note) —
 * unlike 3.2's useMoveDealStage, there's no optimistic patch or 409-conflict rollback
 * here. A plain invalidate-on-success is the correct, simpler pattern for this resource.
 */
export function useCreateContact() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: CreateContactInput): Promise<Contact> => {
            const { data } = await apiClient.post('/contacts', input);
            return contactSchema.parse(data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['contacts', tenant?.id] });
        },
    });
}

export function useUpdateContact(contactId: Contact['id']) {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: UpdateContactInput): Promise<Contact> => {
            const { data } = await apiClient.patch(`/contacts/${contactId}`, input);
            return contactSchema.parse(data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['contacts', tenant?.id] });
        },
    });
}

export function useDeleteContact() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (contactId: Contact['id']): Promise<void> => {
            await apiClient.delete(`/contacts/${contactId}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['contacts', tenant?.id] });
        },
    });
}