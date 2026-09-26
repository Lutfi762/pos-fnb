/*
 * The connection badge in the app header.
 *
 * Three states, and amber rather than red for offline: losing the line is
 * something the till is built to handle, not a failure to alarm anyone with.
 */
import { EVENT, flush, stats } from './outbox';

const DAY = 24 * 60 * 60 * 1000;

export default function connectionStatus(labels) {
    return {
        labels,
        online: navigator.onLine,
        syncing: false,
        count: 0,
        oldest: null,
        blocked: null,
        operations: [],
        open: false,

        async init() {
            window.addEventListener('online', () => {
                this.online = true;
            });
            window.addEventListener('offline', () => {
                this.online = false;
            });
            window.addEventListener(EVENT, (event) => this.apply(event.detail));

            try {
                this.apply(await stats());
            } catch {
                // Private browsing can refuse IndexedDB. The badge then only
                // shows the connection, which is still worth knowing.
            }
        },

        apply(detail) {
            if (!detail) {
                return;
            }

            if (detail.flushing) {
                this.syncing = true;

                return;
            }

            this.syncing = false;
            this.count = detail.count ?? 0;
            this.oldest = detail.oldest ?? null;
            this.blocked = detail.blocked ?? null;
            this.operations = detail.operations ?? [];
        },

        get state() {
            if (!this.online) {
                return 'offline';
            }

            return this.syncing || this.count > 0 ? 'syncing' : 'online';
        },

        get label() {
            if (!this.online) {
                return this.labels.offline;
            }

            return this.count > 0 ? this.labels.waiting.replace(':count', this.count) : this.labels.online;
        },

        get stale() {
            return this.oldest !== null && Date.now() - Date.parse(this.oldest) > DAY;
        },

        async syncNow() {
            this.syncing = true;
            await flush();
        },

        commandLabel(command) {
            return this.labels.commands[command] ?? command;
        },

        time(iso) {
            return new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        },
    };
}
