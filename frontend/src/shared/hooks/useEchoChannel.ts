import { useEffect, useRef } from 'react';
import { echo } from '@/shared/lib/echo';

const channelRefCounts = new Map<string, number>();

export function useEchoChannel(channel: string, event: string, onEvent: (payload: unknown) => void) {
    // Always call the latest callback without resubscribing on every render.
    const handlerRef = useRef(onEvent);
    useEffect(() => {
        handlerRef.current = onEvent;
    });

    useEffect(() => {
        if (!channel) return;

        const handler = (payload: unknown) => handlerRef.current(payload);

        echo.private(channel).listen(event, handler);
        channelRefCounts.set(channel, (channelRefCounts.get(channel) ?? 0) + 1);

        return () => {
            echo.private(channel).stopListening(event, handler);

            const remaining = (channelRefCounts.get(channel) ?? 1) - 1;
            if (remaining <= 0) {
                channelRefCounts.delete(channel);
                echo.leave(channel);
            } else {
                channelRefCounts.set(channel, remaining);
            }
        };
    }, [channel, event]);
}
