/*
 * The till's own storage: the queue of commands waiting for the server, the
 * bills rung up offline, and the menu snapshot they were rung up from.
 *
 * IndexedDB survives a reload, a closed tab, and a restarted tablet, which is
 * the whole point: a sale taken offline must still be there tomorrow.
 */
const NAME = 'meja-offline';
const VERSION = 4;

export const OPERATIONS = 'operations';
export const BILLS = 'bills';
export const META = 'meta';

const LEGACY_DRAFTS = 'draft-operations';

let connection = null;

export function open() {
    if (connection) {
        return connection;
    }

    connection = new Promise((resolve, reject) => {
        const request = indexedDB.open(NAME, VERSION);

        request.onupgradeneeded = () => {
            const database = request.result;
            const upgrade = request.transaction;

            if (!database.objectStoreNames.contains(OPERATIONS)) {
                database.createObjectStore(OPERATIONS, { keyPath: 'operation_key' });
            }

            if (!database.objectStoreNames.contains(BILLS)) {
                database.createObjectStore(BILLS, { keyPath: 'client_order_key' });
            }

            if (!database.objectStoreNames.contains(META)) {
                database.createObjectStore(META);
            }

            // Drafts queued by the first offline release move into the general
            // queue under their original key and sequence, so nothing is lost.
            if (database.objectStoreNames.contains(LEGACY_DRAFTS)) {
                const legacy = upgrade.objectStore(LEGACY_DRAFTS).getAll();

                legacy.onsuccess = () => {
                    const operations = upgrade.objectStore(OPERATIONS);

                    for (const draft of legacy.result) {
                        const { operation_key, sequence, client_created_at, blocked_status, ...payload } = draft;

                        operations.put({
                            operation_key,
                            sequence,
                            client_created_at,
                            command: 'order.saved',
                            endpoint: '/sync/draft-orders',
                            payload,
                            blocked: blocked_status ? { status: blocked_status, message: '' } : null,
                        });
                    }

                    database.deleteObjectStore(LEGACY_DRAFTS);
                };
            }
        };

        request.onsuccess = () => {
            request.result.onversionchange = () => {
                request.result.close();
                connection = null;
            };
            resolve(request.result);
        };
        request.onerror = () => {
            connection = null;
            reject(request.error);
        };
    });

    return connection;
}

function done(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
    });
}

function result(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export async function all(store) {
    const database = await open();

    return result(database.transaction(store).objectStore(store).getAll());
}

export async function get(store, key) {
    const database = await open();

    return result(database.transaction(store).objectStore(store).get(key));
}

/**
 * Everything stored here is plain JSON: strings, numbers, arrays, objects.
 * Values often arrive wrapped in Alpine's reactive proxies, which IndexedDB
 * refuses to clone, so each one is copied to a plain value first.
 */
function plain(value) {
    return JSON.parse(JSON.stringify(value));
}

export async function put(store, value, key) {
    const database = await open();
    const transaction = database.transaction(store, 'readwrite');
    const copy = plain(value);

    key === undefined ? transaction.objectStore(store).put(copy) : transaction.objectStore(store).put(copy, key);

    return done(transaction);
}

export async function remove(store, key) {
    const database = await open();
    const transaction = database.transaction(store, 'readwrite');

    transaction.objectStore(store).delete(key);

    return done(transaction);
}

/**
 * Read, change, and write one meta value in a single transaction, so two
 * tabs asking for the next sequence number can never both get the same one.
 */
export async function updateMeta(key, change) {
    const database = await open();
    const transaction = database.transaction(META, 'readwrite');
    const store = transaction.objectStore(META);
    let next;

    const current = store.get(key);
    current.onsuccess = () => {
        next = change(current.result);
        store.put(next, key);
    };

    await done(transaction);

    return next;
}
