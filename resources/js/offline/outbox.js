/*
 * The outbox: every command the till could not send yet, in the order it
 * happened, waiting for a connection.
 *
 * Rules the server relies on:
 *   - commands go one at a time, lowest sequence first;
 *   - a command the server refuses stays at the front and holds the rest
 *     back, because a payment must never overtake the sale it pays for;
 *   - a command is only removed after the server said it was applied (or
 *     that a manager set it aside), never on a timeout.
 */
import { all, BILLS, get, META, OPERATIONS, put, remove, updateMeta } from './db';
import { ulid } from './ulid';

export const EVENT = 'meja:outbox';

const DEFAULT_ENDPOINT = '/sync/operations';

let flushing = null;

async function nextSequence() {
    return updateMeta('last_sequence', (current) => (current || 0) + 1);
}

function announce(detail) {
    window.dispatchEvent(new CustomEvent(EVENT, { detail }));
}

export async function pending() {
    const operations = await all(OPERATIONS);

    return operations.sort((left, right) => left.sequence - right.sequence);
}

export async function stats() {
    const operations = await pending();
    const oldest = operations.length ? operations.reduce((min, op) => (op.client_created_at < min ? op.client_created_at : min), operations[0].client_created_at) : null;

    return {
        count: operations.length,
        oldest,
        blocked: operations.find((operation) => operation.blocked)?.blocked ?? null,
        operations,
    };
}

export async function notify() {
    announce(await stats());
}

/**
 * Queue a command. Returns the stored operation.
 */
export async function enqueue(command, payload, at = new Date()) {
    const operation = {
        operation_key: ulid(),
        sequence: await nextSequence(),
        client_created_at: at.toISOString(),
        command,
        payload,
        blocked: null,
    };

    await put(OPERATIONS, operation);
    await notify();

    return operation;
}

/**
 * Save a bill's cart. While the previous save of the same bill is still
 * waiting to be sent, it is rewritten in place rather than queued twice: the
 * server only needs to hear the latest version of a cart it has never seen.
 */
export async function queueOrderSave(payload) {
    const waiting = (await pending()).find(
        (operation) => operation.command === 'order.saved'
            && operation.payload.client_order_key === payload.client_order_key
            && !operation.blocked,
    );

    if (waiting) {
        waiting.payload = { ...payload, revision: waiting.payload.revision };
        await put(OPERATIONS, waiting);
        await notify();

        return waiting;
    }

    return enqueue('order.saved', payload);
}

async function session() {
    const response = await fetch('/sync/session', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
    });

    const json = (response.headers.get('content-type') || '').includes('application/json');

    if (!response.ok || !json) {
        return null;
    }

    const data = await response.json();

    return data.user_id ? data : null;
}

function body(operation) {
    return {
        ...operation.payload,
        command: operation.command,
        operation_key: operation.operation_key,
        sequence: operation.sequence,
        client_created_at: operation.client_created_at,
    };
}

async function send(operation, csrf) {
    return fetch(operation.endpoint || DEFAULT_ENDPOINT, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify(body(operation)),
    });
}

/**
 * The server numbers commands per device. When the two disagree, for example
 * after the browser's storage or cookie was reset, the queue is renumbered
 * from what the server expects. Nothing was applied twice: every command
 * keeps its own key, which is what the server deduplicates on.
 */
async function renumber(from) {
    const operations = await pending();
    let sequence = from;

    for (const operation of operations) {
        operation.sequence = sequence++;
        await put(OPERATIONS, operation);
    }

    await updateMeta('last_sequence', () => sequence - 1);
}

/**
 * Keep the offline bill in step with what the server now knows about it.
 */
async function remember(operation, data) {
    const key = operation.payload.client_order_key;

    if (!key) {
        return;
    }

    const bill = await get(BILLS, key);

    if (!bill) {
        return;
    }

    if (operation.command === 'order.saved' && data.order_id) {
        bill.server = { order_id: data.order_id, revision: data.revision };

        // The server keeps a number the till minted unless another bill took
        // it first. The receipt then points at the server's number.
        if (data.number && data.number !== bill.number) {
            bill.printed_number = bill.printed_number || bill.number;
            bill.number = data.number;
        }
    }

    if (operation.command === 'payment.recorded' && data.status === 'applied') {
        bill.synced_payment = true;
    }

    bill.updated_at = new Date().toISOString();
    await put(BILLS, bill);
}

async function run() {
    if (!navigator.onLine) {
        return { state: 'offline' };
    }

    let current;

    try {
        current = await session();
    } catch {
        return { state: 'offline' };
    }

    if (!current) {
        return { state: 'signed_out' };
    }

    let csrf = current.csrf;
    let renumbered = 0;
    let refreshed = false;
    let sent = 0;

    for (;;) {
        const [operation] = await pending();

        if (!operation) {
            await updateMeta('last_flush_at', () => new Date().toISOString());

            return { state: 'idle', sent };
        }

        let response;

        try {
            response = await send(operation, csrf);
        } catch {
            return { state: 'offline', sent };
        }

        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        if (response.ok) {
            await remember(operation, data);
            await remove(OPERATIONS, operation.operation_key);
            sent++;
            await notify();
            continue;
        }

        // An expired token: fetch a fresh one once, then try again.
        if (response.status === 419 && !refreshed) {
            refreshed = true;
            const again = await session().catch(() => null);

            if (!again) {
                return { state: 'signed_out', sent };
            }

            csrf = again.csrf;
            continue;
        }

        if (response.status === 409 && ['sequence_gap', 'sequence_taken'].includes(data.code) && renumbered < 3) {
            renumbered++;
            await renumber(data.expected_sequence);
            continue;
        }

        if (response.status === 409 || response.status === 422) {
            operation.blocked = {
                status: response.status,
                code: data.code || null,
                message: data.message || '',
                at: new Date().toISOString(),
            };
            await put(OPERATIONS, operation);
            await notify();

            return { state: 'blocked', sent, blocked: operation.blocked };
        }

        if (response.status === 401 || response.status === 403) {
            return { state: 'signed_out', sent };
        }

        // Anything else is the server having a bad moment. Try again later.
        return { state: 'error', sent };
    }
}

/**
 * Send whatever is waiting. Safe to call as often as you like: calls that
 * overlap share one flush, and other tabs wait for this one.
 */
export function flush() {
    if (flushing) {
        return flushing;
    }

    const work = () => run().finally(() => notify());

    flushing = (navigator.locks?.request ? navigator.locks.request('meja-outbox', work) : work())
        .catch(() => ({ state: 'error' }))
        .finally(() => {
            flushing = null;
        });

    announce({ flushing: true });

    return flushing;
}

export async function lastFlushAt() {
    return get(META, 'last_flush_at');
}
