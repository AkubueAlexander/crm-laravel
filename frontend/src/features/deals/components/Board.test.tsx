import { act, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createTestQueryClient, queryWrapper } from '@/test/queryWrapper';
import type { Deal } from '../schemas/deal';
import { DealsBoard } from './Board';

const h = vi.hoisted(() => ({
    mutate: vi.fn(),
    onDragEnd: null as ((event: unknown) => void) | null,
    echo: null as ((payload: unknown) => void) | null,
}));

// Pointer drags are brittle in jsdom: capture the DndContext handler and call it with a drop event instead.
vi.mock('@dnd-kit/core', async () => {
    const { createElement } = await import('react');
    return {
        DndContext: (props: { children?: ReactNode; onDragEnd?: (event: unknown) => void }) => {
            h.onDragEnd = props.onDragEnd ?? null;
            return createElement('div', null, props.children);
        },
        PointerSensor: class {},
        useSensor: () => ({}),
        useSensors: () => [],
        useDroppable: () => ({ setNodeRef: () => undefined, isOver: false }),
    };
});

vi.mock('../api/useDeals', async () => {
    const { useQuery } = await import('@tanstack/react-query');
    return {
        useDeals: () =>
            useQuery<Deal[]>({
                queryKey: ['deals', 1, 1],
                queryFn: () => Promise.resolve([makeDeal()]),
                staleTime: Infinity,
            }),
    };
});

vi.mock('../api/useMoveDealStage', () => ({
    useMoveDealStage: () => ({ mutate: h.mutate }),
    isStaleDealError: (error: unknown) => (error as { stale?: boolean } | null)?.stale === true,
}));

vi.mock('@/shared/hooks/useTenant', () => ({
    useTenant: () => ({ tenant: { id: 1 }, isLoading: false }),
}));

vi.mock('@/shared/hooks/usePermission', () => ({ usePermission: () => false }));

vi.mock('@/shared/hooks/useEchoChannel', () => ({
    useEchoChannel: (_channel: string, _event: string, cb: (payload: unknown) => void) => {
        h.echo = cb;
    },
}));

vi.mock('./DealCard', async () => {
    const { createElement } = await import('react');
    return {
        DealCard: ({ deal }: { deal: { id: number; name: string } }) => createElement('div', { 'data-testid': `card-${deal.id}` }, deal.name),
    };
});

vi.mock('@/features/activities/components/ActivityTimelineModal', () => ({
    ActivityTimelineModal: () => null,
}));

function makeDeal(): Deal {
    return {
        id: 1,
        board_id: 1,
        name: 'Renewal deal',
        owner_id: null,
        amount: '1000.00',
        expected_close_date: null,
        stage: 'qualified',
        stage_label: 'Qualified',
        lock_version: 2,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
    };
}

async function renderBoard() {
    const client = createTestQueryClient();
    render(<DealsBoard />, { wrapper: queryWrapper(client) });
    await screen.findByTestId('card-1');
    return client;
}

function column(label: string): HTMLElement {
    const el = screen.getByText(label).parentElement?.parentElement;
    if (!el) throw new Error(`Column not found: ${label}`);
    return el;
}

function drop(toStage: string) {
    act(() => {
        h.onDragEnd?.({ active: { data: { current: { deal: makeDeal() } } }, over: { id: toStage } });
    });
}

describe('DealsBoard (3.3 / 3.4)', () => {
    beforeEach(() => {
        h.mutate.mockReset();
        h.onDragEnd = null;
        h.echo = null;
    });

    it('renders the deal in its stage column', async () => {
        await renderBoard();
        expect(within(column('Qualified')).getByTestId('card-1')).toBeTruthy();
    });

    it('a drop on another stage calls the transition mutation with the lock version', async () => {
        await renderBoard();
        drop('won');
        expect(h.mutate).toHaveBeenCalledTimes(1);
        expect(h.mutate).toHaveBeenCalledWith({ dealId: 1, toStage: 'won', lockVersion: 2 }, expect.any(Object));
    });

    it('a drop on the same stage does nothing', async () => {
        await renderBoard();
        drop('qualified');
        expect(h.mutate).not.toHaveBeenCalled();
    });

    it('a 409 shows the updated-by-someone-else message', async () => {
        h.mutate.mockImplementation((_vars: unknown, opts: { onError: (error: unknown) => void }) => opts.onError({ stale: true }));
        await renderBoard();
        drop('won');
        expect(screen.getByText(/updated by someone else/)).toBeTruthy();
    });

    it('any other failure shows a generic message', async () => {
        h.mutate.mockImplementation((_vars: unknown, opts: { onError: (error: unknown) => void }) => opts.onError(new Error('boom')));
        await renderBoard();
        drop('won');
        expect(screen.getByText(/Something went wrong/)).toBeTruthy();
    });

    it('a mocked Echo event patches the card into its new column', async () => {
        await renderBoard();
        expect(h.echo).not.toBeNull();
        act(() => {
            h.echo?.({ deal_id: 1, to_stage: 'won', lock_version: 3 });
        });
        await waitFor(() => expect(within(column('Won')).getByTestId('card-1')).toBeTruthy());
        expect(within(column('Qualified')).queryByTestId('card-1')).toBeNull();
    });
});
