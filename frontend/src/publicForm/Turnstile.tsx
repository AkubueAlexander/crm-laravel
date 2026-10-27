import { useEffect, useRef } from 'react';

type TurnstileApi = {
    render: (
        el: HTMLElement,
        opts: {
            sitekey: string;
            callback: (token: string) => void;
            'expired-callback': () => void;
            'error-callback': () => void;
        },
    ) => string;
    reset: (id?: string) => void;
    remove: (id: string) => void;
};

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

const SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
let scriptPromise: Promise<void> | null = null;

function loadScript(): Promise<void> {
    if (window.turnstile) return Promise.resolve();
    if (scriptPromise) return scriptPromise;
    scriptPromise = new Promise<void>((resolve, reject) => {
        const s = document.createElement('script');
        s.src = SRC;
        s.async = true;
        s.defer = true;
        s.onload = () => resolve();
        s.onerror = () => {
            scriptPromise = null;
            reject(new Error('Turnstile failed to load'));
        };
        document.head.appendChild(s);
    });
    return scriptPromise;
}

type Props = {
    siteKey: string;
    onToken: (token: string | null) => void;
    /** Increment to reset the widget (e.g. after a failed submit: tokens are single-use). */
    resetKey: number;
};

export function Turnstile({ siteKey, onToken, resetKey }: Props) {
    const ref = useRef<HTMLDivElement>(null);
    const widgetId = useRef<string | null>(null);
    const cb = useRef(onToken);

    useEffect(() => {
        cb.current = onToken;
    }, [onToken]);

    useEffect(() => {
        let cancelled = false;

        loadScript()
            .then(() => {
                if (cancelled || !ref.current || !window.turnstile) return;
                widgetId.current = window.turnstile.render(ref.current, {
                    sitekey: siteKey,
                    callback: (t) => cb.current(t),
                    'expired-callback': () => cb.current(null),
                    'error-callback': () => cb.current(null),
                });
            })
            .catch(() => cb.current(null));

        return () => {
            cancelled = true;
            if (widgetId.current && window.turnstile) {
                window.turnstile.remove(widgetId.current);
            }
            widgetId.current = null;
        };
    }, [siteKey]);

    useEffect(() => {
        if (resetKey > 0 && widgetId.current && window.turnstile) {
            window.turnstile.reset(widgetId.current);
        }
    }, [resetKey]);

    return <div ref={ref} />;
}
