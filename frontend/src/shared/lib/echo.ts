import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { apiClient } from './apiClient';

(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;


export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
        authorize: (socketId: string, callback: (error: boolean, data?: unknown) => void) => {
            apiClient
                .post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                .then((response) => callback(false, response.data))
                .catch((error) => callback(true, error));
        },
    }),
});
