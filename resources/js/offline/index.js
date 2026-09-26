/*
 * Wires the offline pieces into every signed-in page.
 *
 * Signed-in pages carry <meta name="meja-app">. Only those refresh the menu
 * snapshot, flush the outbox, and ask the service worker to keep a copy of
 * the offline till, because only there is anyone allowed to see them.
 */
import connectionStatus from './status';
import offlineTerminal, { handOff } from './terminal';
import { flush } from './outbox';
import { refresh as refreshSnapshot } from './snapshot';

const FLUSH_EVERY = 60 * 1000;
const SNAPSHOT_EVERY = 3 * 60 * 1000;

function signedIn() {
    return document.querySelector('meta[name="meja-app"]') !== null;
}

function registerComponents() {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('offlineTerminal', offlineTerminal);
        window.Alpine.data('connectionStatus', connectionStatus);
    });
}

function registerWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', async () => {
        try {
            await navigator.serviceWorker.register('/service-worker.js');
            const ready = await navigator.serviceWorker.ready;

            // Keep a fresh copy of the offline till for when the line drops.
            if (signedIn() && navigator.onLine) {
                ready.active?.postMessage({ type: 'cache-shell' });
            }
        } catch {
            // No service worker (plain HTTP, private window): the app still
            // works online, it just cannot open while offline.
        }
    });
}

/**
 * On the online till, a save or pay pressed while offline hands the cart to
 * the offline till instead of failing against a server it cannot reach.
 */
function interceptOfflineSaves() {
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-offline-handoff]');

        if (!button || navigator.onLine) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        const root = button.closest('[wire\\:id]');
        const wire = root && window.Livewire?.find(root.getAttribute('wire:id'));

        if (!wire) {
            return;
        }

        if (wire.orderId) {
            window.dispatchEvent(new CustomEvent('meja:handoff-refused'));

            return;
        }

        try {
            await handOff(wire);
        } catch {
            // Storage refused: keep the cart on screen rather than lose it.
            return;
        }

        window.location.href = '/pos/offline';
    }, true);
}

export function installOffline() {
    registerComponents();
    registerWorker();

    if (!signedIn()) {
        return;
    }

    interceptOfflineSaves();

    const sync = () => {
        if (navigator.onLine) {
            flush();
        }
    };

    window.addEventListener('online', () => {
        sync();
        refreshSnapshot();
    });

    window.addEventListener('load', () => {
        sync();
        refreshSnapshot();
    });

    setInterval(sync, FLUSH_EVERY);
    setInterval(() => navigator.onLine && refreshSnapshot(), SNAPSHOT_EVERY);
}
