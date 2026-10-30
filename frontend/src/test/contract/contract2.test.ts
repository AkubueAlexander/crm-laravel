import { describe, expect, it } from 'vitest';
import { contactSchema } from '@/features/contacts/schemas/contact';
import { invoiceLineSchema, invoiceSchema } from '@/features/invoices/schemas/invoice';
import { paymentSchema } from '@/features/invoices/schemas/payment';

// 15.4b: contacts + invoices (incl. lines and payments). Same approach as contract.test.ts (15.4).

type AnySchema = {
    safeParse: (value: unknown) => { success: boolean; error?: { issues: unknown } };
    shape: Record<string, unknown>;
};

const files = import.meta.glob<{ default: Record<string, unknown> }>('./*.json', { eager: true });

function fixture(name: string): Record<string, unknown> {
    const mod = files[`./${name}.json`];
    if (!mod) throw new Error(`Missing contract fixture ${name}.json - run the backend tests (php artisan test tests/Feature/Contract).`);
    return mod.default;
}

function check(schema: AnySchema, data: Record<string, unknown>, label: string) {
    const result = schema.safeParse(data);
    expect(result.success, `${label} failed to parse: ${JSON.stringify(result.error?.issues)}`).toBe(true);
    const extra = Object.keys(data).filter((key) => !(key in schema.shape));
    expect(extra, `${label}: keys sent by the API but not declared in the Zod schema`).toEqual([]);
}

function checkRows(schema: AnySchema, rows: unknown, label: string) {
    expect(Array.isArray(rows) && rows.length > 0, `${label}: expected a non-empty array`).toBe(true);
    (rows as Record<string, unknown>[]).forEach((row, i) => check(schema, row, `${label} ${i + 1}`));
}

describe('API contract (15.4b): contacts and invoices', () => {
    it('contact-full parses and declares every key', () => {
        check(contactSchema as unknown as AnySchema, fixture('contact-full'), 'contact-full');
    });

    it('contact-nullable parses and declares every key', () => {
        check(contactSchema as unknown as AnySchema, fixture('contact-nullable'), 'contact-nullable');
    });

    it('invoice-full parses, and its lines and payments declare every key', () => {
        const data = fixture('invoice-full');
        check(invoiceSchema as unknown as AnySchema, data, 'invoice-full');
        checkRows(invoiceLineSchema as unknown as AnySchema, data.lines, 'invoice-full line');
        checkRows(paymentSchema as unknown as AnySchema, data.payments, 'invoice-full payment');
    });

    it('invoice-list parses and declares every key', () => {
        const data = fixture('invoice-list');
        check(invoiceSchema as unknown as AnySchema, data, 'invoice-list');
        expect(data.lines_count).toBe(3);
        expect('lines' in data).toBe(false);
    });
});
