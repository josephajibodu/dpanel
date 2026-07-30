import { configureEcho, echo } from '@laravel/echo-react';

const wsHost = import.meta.env.VITE_REVERB_HOST ?? window.location.hostname;
const wsPort = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);
const wsScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const forceTLS = wsScheme === 'https';
const realtimeDebugEnabled = import.meta.env.VITE_REALTIME_DEBUG === 'true';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost,
    wsPort,
    wssPort: wsPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
    },
});

if (realtimeDebugEnabled) {
    const pusherConnection = (echo() as any)?.connector?.pusher?.connection;
    pusherConnection?.bind('state_change', (states: { previous: string; current: string }) => {
        console.debug('[realtime] connection state change', states);
    });
    pusherConnection?.bind('error', (error: unknown) => {
        console.error('[realtime] connection error', error);
    });
}
