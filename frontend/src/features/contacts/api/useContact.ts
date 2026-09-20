import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { contactSchema, type Contact } from '../schemas/contact';

export function useContact(contactId: Contact['id'] | undefined) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['contacts', tenant?.id, 'detail', contactId] as const,
        queryFn: async (): Promise<Contact> => {
            const { data } = await apiClient.get(`/contacts/${contactId}`);
            return contactSchema.parse(data);
        },
        enabled: !!tenant?.id && !!contactId,
    });
}