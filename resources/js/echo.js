import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// The browser-facing Reverb host/port/scheme are baked into this bundle at
// asset-build time. In dev they are set explicitly (see .env: the panel and
// Reverb sit behind Caddy at kixctl.lan.kixago.com:443), so the fallbacks below
// never engage. On the appliance the operator's hostname isn't known when the
// image is built, so those vars are left unset and the socket connects back to
// the page's own origin — the same Caddy edge that served the panel, which
// reverse-proxies /app/* to the local Reverb server. Only the app key is baked
// (it's public by protocol design); the secret stays server-side.
const loc = window.location;
const scheme = import.meta.env.VITE_REVERB_SCHEME
    ?? (loc.protocol === 'https:' ? 'https' : 'http');
const port = Number(import.meta.env.VITE_REVERB_PORT)
    || Number(loc.port)
    || (scheme === 'https' ? 443 : 80);

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || loc.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
