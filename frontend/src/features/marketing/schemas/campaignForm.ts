import { z } from 'zod';

// UX layer only: mirrors SaveCampaignRequest (9.1a). The API Form Request is the security boundary,
// and the case-insensitive unique name rule can only be enforced server-side (mapped back via 422).
export const campaignFormSchema = z.object({
    name: z.string().trim().min(1, 'Name is required.').max(160, 'Name must be 160 characters or fewer.'),
    subject: z.string().trim().max(255, 'Subject must be 255 characters or fewer.'),
});

export type CampaignFormValues = z.infer<typeof campaignFormSchema>;

// What the API receives: an empty subject is sent as null.
export type CampaignInput = {
    name: string;
    subject: string | null;
};

export function toCampaignInput(values: CampaignFormValues): CampaignInput {
    const subject = values.subject.trim();
    return { name: values.name.trim(), subject: subject === '' ? null : subject };
}
