import { z } from 'zod';

// Mirrors RecordPaymentAction::METHODS (10.6a). The server is the authority.
export const PAYMENT_METHODS = ['bank_transfer', 'card', 'cash', 'cheque', 'other'] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
    bank_transfer: 'Bank transfer',
    card: 'Card',
    cash: 'Cash',
    cheque: 'Cheque',
    other: 'Other',
};

// Mirrors PaymentResource (10.6b). Money is a decimal string. Reversed payments stay in the list.
export const paymentSchema = z.object({
    id: z.number(),
    amount: z.string(),
    method: z.enum(PAYMENT_METHODS),
    paid_on: z.string().nullable(),
    reference: z.string().nullable(),
    note: z.string().nullable(),
    recorded_by: z.number().nullable(),
    voided_at: z.string().nullable(),
    void_reason: z.string().nullable(),
    created_at: z.string().nullable(),
});

export type Payment = z.infer<typeof paymentSchema>;

export function todayIso(): string {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

// UX layer only: RecordPaymentAction re-validates everything under the invoice row lock (14.2).
export const paymentFormSchema = z.object({
    amount: z
        .string()
        .trim()
        .regex(/^\d{1,12}(\.\d{1,2})?$/, 'Enter a valid amount (up to 2 decimals).')
        .refine((v) => Number(v) > 0, 'Amount must be greater than zero.'),
    method: z.enum(PAYMENT_METHODS),
    paid_on: z
        .string()
        .min(1, 'Pick a date.')
        .refine((v) => v <= todayIso(), 'The payment date cannot be in the future.'),
    reference: z.string().trim().max(120, 'Keep it under 120 characters.'),
    note: z.string().trim().max(1000, 'Keep it under 1000 characters.'),
});

export type PaymentFormValues = z.infer<typeof paymentFormSchema>;

export const reversePaymentSchema = z.object({
    reason: z.string().trim().min(1, 'Enter a reason for reversing this payment.').max(1000, 'Keep it under 1000 characters.'),
});

export type ReversePaymentValues = z.infer<typeof reversePaymentSchema>;
