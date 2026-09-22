const DATABASE = 'meja-offline';
const STORE = 'draft-operations';
const META = 'metadata';

function id() {
    return crypto.randomUUID();
}

function open() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE, 3);

        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE)) {
                request.result.createObjectStore(STORE, { keyPath: 'operation_key' });
            }
            if (!request.result.objectStoreNames.contains(META)) {
                request.result.createObjectStore(META);
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function queued() {
    const database = await open();

    return new Promise((resolve, reject) => {
        const request = database.transaction(STORE).objectStore(STORE).getAll();
        request.onsuccess = () => resolve(request.result.sort((left, right) => left.sequence - right.sequence));
        request.onerror = () => reject(request.error);
    });
}

async function put(operation) {
    const database = await open();

    return new Promise((resolve, reject) => {
        const store = database.transaction(STORE, 'readwrite').objectStore(STORE);
        const existing = store.getAll();

        existing.onsuccess = () => {
            const previous = existing.result.find((operation) => ! operation.blocked_status);

            // One visible new cart is one draft. Retain both keys while its
            // cashier edits it offline, so a retry cannot create a second bill.
            if (previous) {
                operation.operation_key = previous.operation_key;
                operation.client_order_key = previous.client_order_key;
                operation.sequence = previous.sequence;
                operation.client_created_at = previous.client_created_at;
                store.delete(previous.operation_key);
            }

            store.put(operation);
        };
        existing.onerror = () => reject(existing.error);
        store.transaction.oncomplete = () => resolve();
        store.transaction.onerror = () => reject(store.transaction.error);
    });
}

async function nextSequence() {
    const database = await open();

    return new Promise((resolve, reject) => {
        const store = database.transaction(META, 'readwrite').objectStore(META);
        const current = store.get('last_sequence');

        current.onsuccess = () => {
            const sequence = (current.result || 0) + 1;
            store.put(sequence, 'last_sequence');
            resolve(sequence);
        };
        current.onerror = () => reject(current.error);
    });
}

async function remove(key) {
    const database = await open();

    return new Promise((resolve, reject) => {
        const request = database.transaction(STORE, 'readwrite').objectStore(STORE).delete(key);
        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
    });
}

async function discardBlockedOfflineDrafts() {
    for (const operation of await queued()) {
        if (operation.blocked_status) {
            await remove(operation.operation_key);
        }
    }
}

function payload(wire) {
    return {
        operation_key: id(),
        sequence: 0,
        client_created_at: new Date().toISOString(),
        client_order_key: id(),
        revision: null,
        type: wire.orderType,
        guest_count: wire.guestCount,
        customer_name: wire.customerName || null,
        lines: wire.cart.map((line) => ({
            variant_id: line.product_variant_id,
            modifier_ids: line.modifiers.map((modifier) => modifier.modifier_id),
            quantity: line.quantity,
            note: line.note || null,
        })),
    };
}

async function send(operation) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch('/sync/draft-orders', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        credentials: 'same-origin',
        body: JSON.stringify(operation),
    });

    if (response.ok) {
        await remove(operation.operation_key);
        window.dispatchEvent(new CustomEvent('offline-draft-sent'));

        return 'sent';
    }

    // Conflict and validation failures need a cashier, never an automatic retry.
    if (response.status === 409 || response.status === 422) {
        operation.blocked_status = response.status;
        await put(operation);
        window.dispatchEvent(new CustomEvent('offline-draft-blocked', { detail: { status: response.status } }));

        return 'blocked';
    }

    return 'retry';
}

export async function flushOfflineDrafts() {
    if (!navigator.onLine) {
        return;
    }

    for (const operation of await queued()) {
        if (operation.blocked_status) {
            window.dispatchEvent(new CustomEvent('offline-draft-blocked', { detail: { status: operation.blocked_status } }));

            return;
        }

        try {
            if ((await send(operation)) !== 'sent') {
                return;
            }
        } catch {
            return;
        }
    }
}

export function installOfflineDraftQueue() {
    window.addEventListener('online', () => void flushOfflineDrafts());
    window.addEventListener('load', () => void flushOfflineDrafts());
    window.addEventListener('offline-draft-discard', () => void discardBlockedOfflineDrafts());

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-offline-draft-save]');

        if (!button || navigator.onLine) {
            return;
        }

        const root = button.closest('[wire\\:id]');
        const wire = root && window.Livewire?.find(root.getAttribute('wire:id'));

        // Existing orders have no browser-safe client key in this release.
        if (!wire || wire.orderId || wire.cart.length === 0) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        try {
            const operation = payload(wire);
            operation.sequence = await nextSequence();
            await put(operation);
            window.dispatchEvent(new CustomEvent('offline-draft-queued'));
        } catch {
            // Storage can be disabled or full. Keep the cart on screen instead.
        }
    }, true);
}