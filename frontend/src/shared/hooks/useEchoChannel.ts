import { useEffect } from 'react';
import { echo } from '@/shared/lib/echo';

export function useEchoChannel(channel: string, event: string, onEvent: (payload: unknown) => void) {
    useEffect(() => {
        if (!channel) return;

        echo.private(channel).listen(event, onEvent);

        return () => {
            echo.leave(channel);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [channel, event]);
}
