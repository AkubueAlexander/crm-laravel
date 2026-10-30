import { describe, expect, it } from 'vitest';
import { dealSchema } from '@/features/deals/schemas/deal';
import { quoteLineSchema, quoteSchema } from '@/features/quotes/schemas/quote';

// 15.4: parses the Resource output committed by the Pest contract tests with the real Zod schemas.
// Fails when (a) a fixture no longer parses, or (b) the Resource sends a key the schema does not declare.

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

const cases: { name: string; schema: AnySchema; lines?: boolean }[] = [
    { name: 'deal-full', schema: dealSchema as unknown as AnySchema },
    { name: 'deal-nullable', schema: dealSchema as unknown as AnySchema },
    { name: 'quote-full', schema: quoteSchema as unknown as AnySchema, lines: true },
    { name: 'quote-list', schema: quoteSchema as unknown as AnySchema },
];

describe('API contract (15.4): Resource fixtures vs Zod schemas', () => {
    for (const c of cases) {
        it(`${c.name} parses and declares every key`, () => {
            const data = fixture(c.name);
            check(c.schema, data, c.name);

            if (c.lines) {
                const lines = data.lines as Record<string, unknown>[];
                expect(Array.isArray(lines) && lines.length > 0).toBe(true);
                lines.forEach((line, i) => check(quoteLineSchema as unknown as AnySchema, line, `${c.name} line ${i + 1}`));
            }
        });
    }
});
