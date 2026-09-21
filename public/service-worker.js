const CACHE = 'meja-assets-v1';
const STATIC_ASSETS = [
    '/favicon.ico',
    '/favicon.svg',
    '/apple-touch-icon.png',
    '/icon-192.png',
    '/icon-512.png',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(STATIC_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(
        keys.filter((key) => key.startsWith('meja-assets-') && key !== CACHE).map((key) => caches.delete(key)),
    )));
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || request.mode === 'navigate') {
        return;
    }

    if (! ['style', 'script', 'font', 'image'].includes(request.destination) && ! url.pathname.startsWith('/build/')) {
        return;
    }

    event.respondWith(caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
        if (response.ok) {
            caches.open(CACHE).then((cache) => cache.put(request, response.clone()));
        }

        return response;
    })));
});