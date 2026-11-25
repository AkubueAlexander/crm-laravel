import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    activitySchema,
    type Activity,
    type ActivityPayload,
    type CreateActivityInput,
} from '../schemas/activity';


function useInvalidateActivities() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    return () => queryClient.invalidateQueries({ queryKey: ['activities', tenant?.id] });
}

export function useCreateActivity() {
    const invalidate = useInvalidateActivities();

    return useMutation({
        mutationFn: async (input: CreateActivityInput): Promise<Activity> => {
            const { data } = await apiClient.post('/activities', input);
            return activitySchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export function useUpdateActivity() {
    const invalidate = useInvalidateActivities();

    return useMutation({
        mutationFn: async ({ id, input }: { id: Activity['id']; input: ActivityPayload }): Promise<Activity> => {
            const { data } = await apiClient.patch(`/activities/${id}`, input);
            return activitySchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export function useCompleteActivity() {
    const invalidate = useInvalidateActivities();

    return useMutation({
        mutationFn: async ({ id, completed }: { id: Activity['id']; completed: boolean }): Promise<Activity> => {
            const { data } = await apiClient.post(`/activities/${id}/complete`, { completed });
            return activitySchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export function useDeleteActivity() {
    const invalidate = useInvalidateActivities();

    return useMutation({
        mutationFn: async (id: Activity['id']): Promise<void> => {
            await apiClient.delete(`/activities/${id}`);
        },
        onSuccess: invalidate,
    });
}
