/*
 * The menu, tables, and outlet settings the offline till rings up with.
 *
 * Refreshed every time a signed-in page loads while online, so the copy the
 * till falls back on is never older than the last time anyone used it.
 */
import { get, META, put, updateMeta } from './db';

export const SNAPSHOT_EVENT = 'meja:snapshot';

/** First number a till mints offline, per device per day. */
const OFFLINE_NUMBER_BASE = 5000;

export async function current() {
    return get(META, 'snapshot');
}

export async function refresh() {
    if (!navigator.onLine) {
        return null;
    }

    try {
        const response = await fetch('/pos/offline-data', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
            return null;
        }

        const snapshot = await response.json();

        await put(META, snapshot, 'snapshot');
        window.dispatchEvent(new CustomEvent(SNAPSHOT_EVENT, { detail: snapshot }));

        return snapshot;
    } catch {
        return null;
    }
}

function today() {
    const now = new Date();
    const yy = String(now.getFullYear()).slice(-2);
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');

    return `${yy}${mm}${dd}`;
}

/**
 * The next bill number for this till while offline.
 *
 * Same shape as the server's, {outlet}-{device}-{yymmdd}-{sequence}, but
 * counted from 5001 so it never lands on a number the server hands this same
 * till once the line is back. The server keeps it when the sale is synced.
 */
export async function nextNumber(snapshot) {
    const date = today();
    const known = snapshot.device.last_number?.date === date ? snapshot.device.last_number.sequence : 0;

    const counter = await updateMeta('numbers', (state) => {
        const base = Math.max(OFFLINE_NUMBER_BASE, known);
        const last = state?.date === date ? Math.max(state.sequence, base) : base;

        return { date, sequence: last + 1 };
    });

    return `${snapshot.outlet.code}-${snapshot.device.prefix}-${date}-${String(counter.sequence).padStart(4, '0')}`;
}
