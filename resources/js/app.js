/*
 * The app shell stays available when a connection drops. The worker only
 * caches public assets; HTML and write requests always need the server, so a
 * stale session or a queued sale can never be mistaken for a saved one.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js'));
}
