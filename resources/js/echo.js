import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/*
| Websockets, loaded only by the screens that need them. The cashier does not
| need a socket to ring up a bill, so this is its own entry point rather than
| part of app.js.
*/
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],

    // The board keeps polling on its own, so a dead Reverb costs freshness,
    // never the shift. Give up reconnecting loudly rather than hammering.
    activityTimeout: 30000,
});
