import { act, renderHook, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it, vi } from 'vitest';
import { server } from '@/test/server';
import { createTestQueryClient, queryWrapper } from '@/test/queryWrapper';
import type { Deal, DealStage } from '../schemas/deal';
import { isStaleDealError, useMoveDealStage } from './useMoveDealStage';

vi.mock('@/shared/hooks/useTenant', () => ({ useTenant: () => ({ tenant: { id: 7 } }) }));

const KEY = ['deals', 7, 1] as const;
const deal = (id: number, stage: string) => ({ id, stage, lock_version: 1 }) as unknown as Deal;

describe('useMoveDealStage (3.2)', () => {
    it('moves the card optimistically, then rolls back and flags a stale deal on 409', async () => {
        let release!: () => void;
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        let body: unknown;

        server.use(
            http.post('*/api/v1/deals/1/transition', async ({ request }) => {
                body = await request.json();
                await gate;
                return HttpResponse.json(
                    { message: 'This deal was updated by someone else.', errors: [], code: 'conflict' },
                    { status: 409 },
                );
            }),
        );

        const client = createTestQueryClient();
        client.setQueryData<Deal[]>(KEY, [deal(1, 'qualified'), deal(2, 'lead')]);
        const { result } = renderHook(() => useMoveDealStage(1), { wrapper: queryWrapper(client) });

        act(() => {
            result.current.mutate({ dealId: 1, toStage: 'won' as unknown as DealStage, lockVersion: 3 });
        });

        // Optimistic: the dragged card is already in its new column while the request is in flight.
        await waitFor(() => expect(client.getQueryData<Deal[]>(KEY)?.[0]?.stage).toBe('won'));

        release();
        await waitFor(() => expect(result.current.isError).toBe(true));

        // Rollback: the card is back, the neighbour was never touched.
        expect(client.getQueryData<Deal[]>(KEY)?.[0]?.stage).toBe('qualified');
        expect(client.getQueryData<Deal[]>(KEY)?.[1]?.stage).toBe('lead');
        expect(isStaleDealError(result.current.error)).toBe(true);
        expect(body).toEqual({ to_stage: 'won', lock_version: 3 });
    });

    it('does not treat other errors as a stale deal', () => {
        expect(isStaleDealError(new Error('network'))).toBe(false);
    });
});
