import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AxiosError, type AxiosResponse } from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QuoteBuilder } from './QuoteBuilder';

const h = vi.hoisted(() => ({
    save: vi.fn(),
    navigate: vi.fn(),
}));

vi.mock('@tanstack/react-router', async () => {
    const { createElement } = await import('react');
    return {
        Link: (props: { children?: unknown; to?: string }) => createElement('a', { href: props.to }, props.children as never),
        useNavigate: () => h.navigate,
        useParams: () => ({}),
    };
});

vi.mock('@/shared/hooks/usePermission', () => ({ usePermission: () => true }));

vi.mock('../api/useQuotes', () => ({
    useQuote: () => ({ isPending: false, isError: false, data: undefined, error: null }),
}));

vi.mock('../api/useQuoteMutations', () => ({
    useSaveQuote: () => ({ mutateAsync: h.save }),
}));

vi.mock('../api/useQuoteVersions', () => ({
    useQuoteVersions: () => ({ data: [] }),
    useReviseQuote: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

vi.mock('@/features/products/api/useProducts', () => ({
    useProducts: () => ({ isPending: false, data: { data: [] } }),
    useProduct: () => ({ isPending: false, isError: false, data: undefined }),
}));

vi.mock('./QuoteWorkflowBar', () => ({ QuoteWorkflowBar: () => null }));
vi.mock('@/shared/components/PdfPreview', () => ({ PdfPreview: () => null }));

function setField(label: string, value: string) {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

function addCustomLine(n: number, name: string, qty: string, price: string) {
    fireEvent.click(screen.getByRole('button', { name: 'Add custom line' }));
    setField(`Line ${n} name`, name);
    setField(`Line ${n} quantity`, qty);
    setField(`Line ${n} unit price`, price);
}

function subtotalText(): string {
    return screen.getByText('Subtotal').nextElementSibling?.textContent ?? '';
}

function submit() {
    fireEvent.click(screen.getByRole('button', { name: 'Create quote' }));
}

describe('QuoteBuilder (10.1)', () => {
    beforeEach(() => {
        h.save.mockReset();
        h.navigate.mockReset();
    });

    it('recomputes the subtotal as line items are edited (useWatch)', async () => {
        render(<QuoteBuilder />);
        addCustomLine(1, 'Widget', '2', '10.00');
        addCustomLine(2, 'Gadget', '1', '5.00');
        await waitFor(() => expect(subtotalText()).toContain('25.00'));

        setField('Line 1 quantity', '3');
        await waitFor(() => expect(subtotalText()).toContain('35.00'));
    });

    it('blocks submit and flags the title when the form is empty', async () => {
        render(<QuoteBuilder />);
        submit();
        await waitFor(() => expect(screen.getByLabelText('Title').getAttribute('aria-invalid')).toBe('true'));
        expect(h.save).not.toHaveBeenCalled();
    });

    it('flags the name of a custom line left blank', async () => {
        render(<QuoteBuilder />);
        setField('Title', 'Support plan');
        fireEvent.click(screen.getByRole('button', { name: 'Add custom line' }));
        setField('Line 1 unit price', '10.00');
        submit();
        await waitFor(() => expect(screen.getByLabelText('Line 1 name').getAttribute('aria-invalid')).toBe('true'));
        expect(h.save).not.toHaveBeenCalled();
    });

    it('flags a zero quantity under that line', async () => {
        render(<QuoteBuilder />);
        setField('Title', 'Support plan');
        addCustomLine(1, 'Widget', '0', '10.00');
        submit();
        await waitFor(() => expect(screen.getByLabelText('Line 1 quantity').getAttribute('aria-invalid')).toBe('true'));
        expect(h.save).not.toHaveBeenCalled();
    });

    it('maps a server 422 onto the matching field', async () => {
        const response = {
            status: 422,
            data: { message: 'Invalid', errors: { title: ['That title is taken.'] }, code: 'validation_failed' },
        } as AxiosResponse;
        h.save.mockRejectedValue(new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, response));

        render(<QuoteBuilder />);
        setField('Title', 'Support plan');
        addCustomLine(1, 'Widget', '1', '10.00');
        submit();

        expect(await screen.findByText('That title is taken.')).toBeTruthy();
        expect(screen.getByLabelText('Title').getAttribute('aria-invalid')).toBe('true');
        expect(h.save).toHaveBeenCalledTimes(1);
    });
});
