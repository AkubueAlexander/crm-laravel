import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import type { Contact, CreateContactInput, UpdateContactInput } from '../schemas/contact';
import { contactSchema } from '../schemas/contact';


function useInvalidateAfterContactChange() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return () => {
        queryClient.invalidateQueries({ queryKey: ['contacts', tenant?.id] });
        queryClient.invalidateQueries({ queryKey: ['accounts', tenant?.id] });
    };
}

export function useCreateContact() {
    const invalidate = useInvalidateAfterContactChange();

    return useMutation({
        mutationFn: async (input: CreateContactInput): Promise<Contact> => {
            const { data } = await apiClient.post('/contacts', input);
            return contactSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export function useUpdateContact(contactId: Contact['id']) {
    const invalidate = useInvalidateAfterContactChange();

    return useMutation({
        mutationFn: async (input: UpdateContactInput): Promise<Contact> => {
            const { data } = await apiClient.patch(`/contacts/${contactId}`, input);
            return contactSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export function useDeleteContact() {
    const invalidate = useInvalidateAfterContactChange();

    return useMutation({
        mutationFn: async (contactId: Contact['id']): Promise<void> => {
            await apiClient.delete(`/contacts/${contactId}`);
        },
        onSuccess: invalidate,
    });
}
