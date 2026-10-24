import { z } from 'zod';

// Mirrors InvitationResource (admin side, 8c.2c).
export const invitationSchema = z.object({
    id: z.number(),
    email: z.string(),
    role: z.string(),
    role_label: z.string(),
    invited_by_name: z.string().nullable(),
    expires_at: z.string().nullable(),
    expired: z.boolean(),
    created_at: z.string().nullable(),
});

export type Invitation = z.infer<typeof invitationSchema>;

export const invitationsResponseSchema = z.object({
    data: z.array(invitationSchema),
});

// Mirrors InvitationPreviewResource (public accept page).
export const invitationPreviewSchema = z.object({
    email: z.string(),
    tenant_name: z.string().nullable(),
    role: z.string(),
    role_label: z.string(),
    expires_at: z.string().nullable(),
});

export type InvitationPreview = z.infer<typeof invitationPreviewSchema>;

// UX layer only: the Laravel form requests are the real boundary.
export const inviteFormSchema = z.object({
    email: z.string().trim().min(1, 'Enter an email address.').email('Enter a valid email address.'),
    role: z.string().min(1, 'Choose a role.'),
});

export type InviteFormValues = z.infer<typeof inviteFormSchema>;

export const acceptFormSchema = z
    .object({
        name: z.string().trim().min(1, 'Enter your name.').max(255, 'That name is too long.'),
        password: z.string().min(8, 'Use at least 8 characters.'),
        password_confirmation: z.string(),
    })
    .refine((v) => v.password === v.password_confirmation, {
        message: 'Passwords do not match.',
        path: ['password_confirmation'],
    });

export type AcceptFormValues = z.infer<typeof acceptFormSchema>;
