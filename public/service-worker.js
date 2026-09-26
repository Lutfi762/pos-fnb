/*
 * Meja's service worker.
 *
 * Two caches, two jobs:
 *   - assets: built CSS, JS, fonts and icons, served cache-first. They are
 *     content-hashed, so a cached copy is never a stale copy.
 *   - shell: exactly one page, the offline till. A signed-in page asks for it
 *     to be refreshed; it is only ever served when the network is gone.
 *
 * Every other page and every write always goes to the server. A cached page
 * pretending to be live is how a till shows yesterday's prices.
 */
const ASSETS = 'meja-assets-v2';
const SHELL = 'meja-shell-v1';
const SHELL_URL = '/pos/offline';

const STATIC_ASSETS = [
    '/favicon.ico',
    '/favicon.svg',
    '/apple-touch-icon.png',
    '/icon-192.png',
    '/icon-512.png',
    '/manifest.webmanifest',
];

const OFFLINE_PAGE = `<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Meja - Offline</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#0b1311;color:#e6eee9}
main{max-width:26rem;padding:2rem;text-align:center}
h1{font-size:1.375rem;margin:0 0 .75rem}
p{color:#9aaaa4;line-height:1.5}
a{display:inline-block;margin-top:1rem;padding:.75rem 1.25rem;border-radius:.75rem;background:#3fa98a;color:#06120e;text-decoration:none;font-weight:600}
</style></head>
<body><main>
<h1>Sedang offline</h1>
<p>Halaman ini butuh koneksi. Kasir offline bisa dipakai setelah perangkat ini pernah membuka halaman kasir saat online.</p>
<a href="/pos/offline">Coba buka kasir offline</a>
</main></body></html>`;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(ASSETS).then((cache) => cache.addAll(STATIC_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(
        keys
            .filter((key) => (key.startsWith('meja-assets-') && key !== ASSETS) || (key.startsWith('meja-shell-') && key !== SHELL))
            .map((key) => caches.delete(key)),
    )));
    self.clients.claim();
});

/**
 * Fetch the offline till with the signed-in user's cookies and keep it, but
 * only when the server actually served it: a redirect to the login page or
 * the lock screen must never be cached as the till.
 */
async function cacheShell() {
    try {
        const response = await fetch(SHELL_URL, { credentials: 'same-origin', cache: 'no-store' });
        const html = (response.headers.get('content-type') || '').includes('text/html');

        if (response.ok && !response.redirected && html) {
            const cache = await caches.open(SHELL);
            await cache.put(SHELL_URL, response);
        }
    } catch {
        // Offline right now. The previous copy, if any, stays.
    }
}

self.addEventListener('message', (event) => {
    if (event.data?.type === 'cache-shell') {
        event.waitUntil(cacheShell());
    }
});

async function navigate(request) {
    try {
        return await fetch(request);
    } catch {
        const shell = await caches.match(SHELL_URL, { cacheName: SHELL });

        return shell ?? new Response(OFFLINE_PAGE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Pages: always the network. Only when it is unreachable does the till
    // fall back to the offline copy.
    if (request.mode === 'navigate') {
        event.respondWith(navigate(request));

        return;
    }

    if (! ['style', 'script', 'font', 'image'].includes(request.destination) && ! url.pathname.startsWith('/build/')) {
        return;
    }

    event.respondWith(caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
        if (response.ok) {
            const copy = response.clone();
            caches.open(ASSETS).then((cache) => cache.put(request, copy));
        }

        return response;
    })));
});
