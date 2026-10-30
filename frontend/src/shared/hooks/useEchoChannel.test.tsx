import { renderHook } from '@testing-library/react';
import { useQueryClient } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createTestQueryClient, queryWrapper } from '@/test/queryWrapper';
import { useEchoChannel } from './useEchoChannel';

// A fake Echo that records listeners per channel+event, so a test can emit a "broadcast".
const fake = vi.hoisted(() => {
    type H = (payload: unknown) => void;
    const listeners = new Map<string, H[]>();
    const keyOf = (channel: string, event: string) => channel + '|' + event;

    const channelApi = (channel: string) => ({
        listen(event: string, handler: H) {
            const key = keyOf(channel, event);
            listeners.set(key, [...(listeners.get(key) ?? []), handler]);
            return this;
        },
        stopListening(event: string, handler?: H) {
            const key = keyOf(channel, event);
            listeners.set(
                key,
                (listeners.get(key) ?? []).filter((h) => h !== handler),
            );
            return this;
        },
    });

    return {
        listeners,
        keyOf,
        echo: { private: vi.fn((channel: string) => channelApi(channel)), leave: vi.fn() },
        emit(channel: string, event: string, payload: unknown) {
            for (const handler of listeners.get(keyOf(channel, event)) ?? []) handler(payload);
        },
    };
});

vi.mock('@/shared/lib/echo', () => ({ echo: fake.echo }));

beforeEach(() => {
    fake.listeners.clear();
    fake.echo.private.mockClear();
    fake.echo.leave.mockClear();
});

describe('useEchoChannel', () => {
    it('delivers payloads to the latest callback without resubscribing', () => {
        const first = vi.fn();
        const second = vi.fn();

        const { rerender } = renderHook(({ cb }) => useEchoChannel('tenant.1.board.1', 'DealStageChanged', cb), {
            initialProps: { cb: first },
        });
        rerender({ cb: second });

        fake.emit('tenant.1.board.1', 'DealStageChanged', { id: 5 });

        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledWith({ id: 5 });
        expect(fake.listeners.get(fake.keyOf('tenant.1.board.1', 'DealStageChanged'))).toHaveLength(1);
    });

    it('stops listening and leaves the channel on unmount', () => {
        const { unmount } = renderHook(() => useEchoChannel('tenant.2.board.1', 'DealStageChanged', vi.fn()));

        unmount();

        expect(fake.listeners.get(fake.keyOf('tenant.2.board.1', 'DealStageChanged'))).toHaveLength(0);
        expect(fake.echo.leave).toHaveBeenCalledWith('tenant.2.board.1');
    });

    it('leaves a shared channel only after its last subscriber unmounts', () => {
        const a = renderHook(() => useEchoChannel('tenant.3.board.1', 'DealStageChanged', vi.fn()));
        const b = renderHook(() => useEchoChannel('tenant.3.board.1', 'DealUpdated', vi.fn()));

        a.unmount();
        expect(fake.echo.leave).not.toHaveBeenCalled();

        b.unmount();
        expect(fake.echo.leave).toHaveBeenCalledTimes(1);
        expect(fake.echo.leave).toHaveBeenCalledWith('tenant.3.board.1');
    });

    it('does nothing for an empty channel name', () => {
        renderHook(() => useEchoChannel('', 'DealStageChanged', vi.fn()));

        expect(fake.echo.private).not.toHaveBeenCalled();
    });

    it('lets a broadcast patch the TanStack Query cache (15.5 pattern)', () => {
        type Card = { id: number; stage: string };
        const client = createTestQueryClient();
        client.setQueryData<Card[]>(['deals', 7, 1], [
            { id: 1, stage: 'lead' },
            { id: 2, stage: 'lead' },
        ]);

        renderHook(
            () => {
                const qc = useQueryClient();
                useEchoChannel('tenant.7.board.1', 'DealStageChanged', (payload) => {
                    const { id, stage } = payload as Card;
                    qc.setQueryData<Card[]>(['deals', 7, 1], (old) => old?.map((d) => (d.id === id ? { ...d, stage } : d)));
                });
            },
            { wrapper: queryWrapper(client) },
        );

        fake.emit('tenant.7.board.1', 'DealStageChanged', { id: 1, stage: 'won' });

        expect(client.getQueryData<Card[]>(['deals', 7, 1])).toEqual([
            { id: 1, stage: 'won' },
            { id: 2, stage: 'lead' },
        ]);
    });
});
