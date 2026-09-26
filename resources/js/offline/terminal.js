/*
 * The offline till.
 *
 * Runs entirely in the browser, from the menu snapshot and the outbox, so a
 * cafe keeps selling when the internet drops. It deliberately does less than
 * the online till: no discounts (they need a supervisor the server checks),
 * no card or QRIS (they need the bank), no editing bills another till owns.
 * What it does, it does the same way: same totals, same bill numbers, and
 * every action queued in the order it happened.
 */
import { all, BILLS, get, META, put, remove } from './db';
import { EVENT as OUTBOX_EVENT, enqueue, flush, queueOrderSave, stats } from './outbox';
import { current as currentSnapshot, nextNumber, refresh as refreshSnapshot } from './snapshot';
import { calculateTotals, formatMoney, lineGross, parseMoney, quickAmounts } from './totals';
import { ulid } from './ulid';

const SHIFT_KEY = 'offline_shift';
const HANDOFF_KEY = 'handoff';

/** Bills older than this, fully synced, are cleared from the device. */
const KEEP_DAYS = 3;

function sameModifiers(left, right) {
    return JSON.stringify(left.map((m) => m.id).sort()) === JSON.stringify(right.map((m) => m.id).sort());
}

export default function offlineTerminal(labels) {
    return {
        labels,
        state: 'loading',
        online: navigator.onLine,
        snapshot: null,

        categoryId: '',
        search: '',

        billKey: null,
        cart: [],
        orderType: 'take_away',
        tableId: '',
        guestCount: 1,
        customerName: '',
        cartOpen: false,
        error: '',

        sheet: null,
        sheetError: '',

        payingBill: null,
        tendered: '',
        payError: '',
        receipt: null,

        shift: null,
        shiftPanel: false,
        openingCash: '',
        countedCash: '',
        shiftNote: '',
        moveDirection: 'out',
        moveAmount: '',
        moveReason: '',
        shiftError: '',

        bills: [],
        billsPanel: false,
        outbox: { count: 0, oldest: null, blocked: null },
        syncing: false,
        toastText: '',
        toastTimer: null,

        async init() {
            window.addEventListener('online', () => this.connectionChanged(true));
            window.addEventListener('offline', () => this.connectionChanged(false));
            window.addEventListener(OUTBOX_EVENT, (event) => {
                if (event.detail && 'count' in event.detail) {
                    this.outbox = event.detail;
                }
            });

            this.snapshot = await currentSnapshot();

            if (this.online) {
                this.snapshot = (await refreshSnapshot()) || this.snapshot;
            }

            if (!this.snapshot) {
                this.state = 'empty';

                return;
            }

            await this.loadShift();
            await this.loadBills();
            await this.takeHandoff();

            this.outbox = await stats();
            this.state = 'ready';

            if (this.online) {
                this.sync();
            }
        },

        // Connection ----------------------------------------------------

        connectionChanged(online) {
            this.online = online;

            if (online) {
                this.sync();
            }
        },

        async sync() {
            if (this.syncing) {
                return;
            }

            this.syncing = true;

            try {
                await flush();
                await this.loadBills();
            } finally {
                this.syncing = false;
            }
        },

        async backOnline() {
            await this.sync();

            if (this.outbox.count === 0 || this.outbox.blocked) {
                window.location.href = '/pos';
            }
        },

        // Menu ----------------------------------------------------------

        get categories() {
            return this.snapshot?.categories ?? [];
        },

        get products() {
            const term = this.search.trim().toLowerCase();

            return (this.snapshot?.products ?? []).filter((product) => {
                if (this.categoryId !== '' && product.category_id !== this.categoryId) {
                    return false;
                }

                if (term === '') {
                    return true;
                }

                return product.name.toLowerCase().includes(term)
                    || (product.sku || '').toLowerCase().includes(term)
                    || product.variants.some((variant) => (variant.sku || '').toLowerCase().includes(term));
            });
        },

        priceOf(product) {
            return (product.variants.find((variant) => variant.is_default) ?? product.variants[0])?.price ?? 0;
        },

        tapProduct(product) {
            if (product.variants.length > 1 || product.groups.length > 0) {
                this.openSheet(product);

                return;
            }

            this.addLine(product, product.variants[0], [], '');
        },

        // Modifier sheet ------------------------------------------------

        openSheet(product, index = null) {
            const line = index === null ? null : this.cart[index];
            const variant = product.variants.find((v) => v.is_default) ?? product.variants[0];

            this.sheetError = '';
            this.sheet = {
                product,
                index,
                variantId: line ? line.variant_id : variant?.id,
                chosen: line ? JSON.parse(JSON.stringify(line.chosen || {})) : {},
                note: line ? line.note || '' : '',
            };
        },

        closeSheet() {
            this.sheet = null;
        },

        isChosen(group, modifier) {
            return (this.sheet?.chosen[group.id] || []).includes(modifier.id);
        },

        toggleModifier(group, modifier) {
            const chosen = this.sheet.chosen[group.id] || [];

            if (chosen.includes(modifier.id)) {
                // Never drop below the minimum the group insists on.
                if (chosen.length <= group.min) {
                    return;
                }

                this.sheet.chosen[group.id] = chosen.filter((id) => id !== modifier.id);

                return;
            }

            this.sheet.chosen[group.id] = group.max <= 1 ? [modifier.id] : [...chosen, modifier.id].slice(-group.max);
        },

        sheetModifiers() {
            const rows = [];

            for (const group of this.sheet.product.groups) {
                for (const id of this.sheet.chosen[group.id] || []) {
                    const modifier = group.modifiers.find((m) => m.id === id);

                    if (modifier) {
                        rows.push({ id: modifier.id, name: modifier.name, group: group.name, price: modifier.price });
                    }
                }
            }

            return rows;
        },

        get sheetPrice() {
            if (!this.sheet) {
                return 0;
            }

            const variant = this.sheet.product.variants.find((v) => v.id === this.sheet.variantId);

            return (variant?.price ?? 0) + this.sheetModifiers().reduce((sum, m) => sum + m.price, 0);
        },

        confirmSheet() {
            for (const group of this.sheet.product.groups) {
                if ((this.sheet.chosen[group.id] || []).length < group.min) {
                    this.sheetError = this.labels.choose_min.replace(':count', group.min).replace(':group', group.name);

                    return;
                }
            }

            const variant = this.sheet.product.variants.find((v) => v.id === this.sheet.variantId);

            this.addLine(this.sheet.product, variant, this.sheetModifiers(), this.sheet.note.trim(), this.sheet.index, this.sheet.chosen);
            this.closeSheet();
        },

        // Cart ----------------------------------------------------------

        addLine(product, variant, modifiers, note, replacing = null, chosen = {}) {
            if (!variant) {
                return;
            }

            const line = {
                key: ulid(),
                product_id: product.id,
                variant_id: variant.id,
                product_name: product.name,
                variant_name: variant.name,
                unit_price: variant.price,
                modifier_total: modifiers.reduce((sum, m) => sum + m.price, 0),
                modifiers,
                chosen: JSON.parse(JSON.stringify(chosen)),
                quantity: replacing === null ? 1 : this.cart[replacing].quantity,
                note: note || null,
            };

            if (replacing !== null) {
                this.cart[replacing] = line;

                return;
            }

            // Two taps on the same plain item are one line of two.
            const same = this.cart.find((existing) => existing.variant_id === line.variant_id
                && existing.note === line.note
                && sameModifiers(existing.modifiers, line.modifiers));

            if (same) {
                same.quantity++;

                return;
            }

            this.cart.push(line);
        },

        productFor(line) {
            return (this.snapshot?.products ?? []).find((product) => product.id === line.product_id);
        },

        increase(index) {
            this.cart[index].quantity++;
        },

        decrease(index) {
            if (this.cart[index].quantity <= 1) {
                this.cart.splice(index, 1);

                return;
            }

            this.cart[index].quantity--;
        },

        removeLine(index) {
            this.cart.splice(index, 1);
        },

        lineTotal(line) {
            return lineGross(line);
        },

        get totals() {
            return calculateTotals(this.cart, this.snapshot?.outlet ?? { tax_bps: 0, service_bps: 0, price_includes_tax: true, cash_rounding: 0 });
        },

        get itemCount() {
            return this.cart.reduce((sum, line) => sum + line.quantity, 0);
        },

        get needsTable() {
            return this.orderType === 'dine_in';
        },

        get tableName() {
            return this.snapshot?.tables.find((table) => table.id === this.tableId)?.name ?? '';
        },

        setOrderType(type) {
            this.orderType = type;

            if (type !== 'dine_in') {
                this.tableId = '';
            }
        },

        newOrder() {
            this.billKey = null;
            this.cart = [];
            this.tableId = '';
            this.guestCount = 1;
            this.customerName = '';
            this.error = '';
            this.cartOpen = false;
        },

        money(amount) {
            return formatMoney(amount);
        },

        // Saving and paying ---------------------------------------------

        shiftReference() {
            if (!this.shift) {
                return {};
            }

            return this.shift.id ? { shift_id: this.shift.id } : { client_shift_key: this.shift.key };
        },

        /**
         * Write the cart to an offline bill and queue it for the server.
         */
        async saveBill() {
            this.error = '';

            if (this.cart.length === 0) {
                return null;
            }

            if (this.needsTable && this.tableId === '') {
                this.error = this.labels.table_required;
                this.cartOpen = true;

                return null;
            }

            const existing = this.billKey ? await get(BILLS, this.billKey) : null;
            const key = existing?.client_order_key ?? ulid();
            const number = existing?.number ?? (await nextNumber(this.snapshot));
            const now = new Date().toISOString();

            const bill = {
                client_order_key: key,
                number,
                status: 'open',
                created_at: existing?.created_at ?? now,
                updated_at: now,
                cashier: this.snapshot.user.name,
                type: this.orderType,
                table_id: this.tableId || null,
                table_name: this.tableName || null,
                guest_count: this.guestCount,
                customer_name: this.customerName || null,
                lines: JSON.parse(JSON.stringify(this.cart)),
                totals: this.totals,
                shift: this.shiftReference(),
                server: existing?.server ?? null,
                payment: null,
            };

            await put(BILLS, bill);

            await queueOrderSave({
                client_order_key: key,
                revision: bill.server?.revision ?? null,
                type: bill.type,
                guest_count: bill.guest_count,
                customer_name: bill.customer_name,
                table_id: bill.table_id,
                number: bill.number,
                kitchen_handled: true,
                ...bill.shift,
                lines: bill.lines.map((line) => ({
                    variant_id: line.variant_id,
                    modifier_ids: line.modifiers.map((m) => m.id),
                    quantity: line.quantity,
                    note: line.note,
                })),
            });

            await this.loadBills();

            return bill;
        },

        async holdBill() {
            const bill = await this.saveBill();

            if (bill) {
                this.toast(this.labels.saved.replace(':number', bill.number));
                this.newOrder();
            }
        },

        async checkout() {
            const bill = await this.saveBill();

            if (bill) {
                this.startPayment(bill);
                this.newOrder();
            }
        },

        startPayment(bill) {
            this.payError = '';
            this.payingBill = bill;
            this.tendered = formatMoney(bill.totals.grand_total, false);
            this.billsPanel = false;
        },

        get quickAmounts() {
            return this.payingBill ? quickAmounts(this.payingBill.totals.grand_total) : [];
        },

        get change() {
            return this.payingBill ? Math.max(0, parseMoney(this.tendered) - this.payingBill.totals.grand_total) : 0;
        },

        setTendered(amount) {
            this.tendered = formatMoney(amount, false);
        },

        async confirmPayment() {
            const bill = JSON.parse(JSON.stringify(this.payingBill));
            const due = bill.totals.grand_total;
            const tendered = parseMoney(this.tendered);

            if (!this.snapshot.cash_method) {
                this.payError = this.labels.no_cash_method;

                return;
            }

            if (tendered < due) {
                this.payError = this.labels.not_enough;

                return;
            }

            await enqueue('payment.recorded', {
                client_order_key: bill.client_order_key,
                payment_method_id: this.snapshot.cash_method.id,
                amount: due,
                tendered,
            });

            bill.status = 'paid';
            bill.payment = {
                method: this.snapshot.cash_method.name,
                amount: due,
                tendered,
                change: tendered - due,
                paid_at: new Date().toISOString(),
            };
            bill.updated_at = bill.payment.paid_at;
            bill.shift = bill.shift ?? this.shiftReference();

            await put(BILLS, bill);

            this.payingBill = null;
            this.receipt = bill;

            await this.loadBills();

            if (this.online) {
                this.sync();
            }
        },

        cancelPayment() {
            this.payingBill = null;
        },

        printReceipt() {
            window.print();
        },

        closeReceipt() {
            this.receipt = null;
        },

        // Offline bills -------------------------------------------------

        async loadBills() {
            const cutoff = Date.now() - KEEP_DAYS * 86400000;
            const bills = await all(BILLS);
            const waiting = new Set((await stats()).operations.map((operation) => operation.payload.client_order_key));

            for (const bill of bills) {
                if (Date.parse(bill.updated_at) < cutoff && !waiting.has(bill.client_order_key)) {
                    await remove(BILLS, bill.client_order_key);
                }
            }

            this.bills = (await all(BILLS))
                .filter((bill) => Date.parse(bill.updated_at) >= cutoff)
                .map((bill) => ({ ...bill, waiting: waiting.has(bill.client_order_key) }))
                .sort((left, right) => (left.status === right.status ? right.created_at.localeCompare(left.created_at) : left.status === 'open' ? -1 : 1));
        },

        get openBills() {
            return this.bills.filter((bill) => bill.status === 'open');
        },

        resumeBill(bill) {
            this.billKey = bill.client_order_key;
            this.cart = JSON.parse(JSON.stringify(bill.lines));
            this.orderType = bill.type;
            this.tableId = bill.table_id || '';
            this.guestCount = bill.guest_count;
            this.customerName = bill.customer_name || '';
            this.billsPanel = false;
            this.cartOpen = true;
        },

        showReceipt(bill) {
            this.receipt = bill;
            this.billsPanel = false;
        },

        /**
         * A cart the online till handed over when the line went down.
         */
        async takeHandoff() {
            const handoff = await get(META, HANDOFF_KEY);

            if (!handoff) {
                return;
            }

            await remove(META, HANDOFF_KEY);

            this.cart = handoff.lines;
            this.orderType = handoff.orderType || 'take_away';
            this.tableId = handoff.tableId || '';
            this.guestCount = handoff.guestCount || 1;
            this.customerName = handoff.customerName || '';
            this.cartOpen = this.cart.length > 0;
        },

        // Shift ---------------------------------------------------------

        async loadShift() {
            const offline = await get(META, SHIFT_KEY);

            if (offline && !offline.closed_at) {
                this.shift = offline;

                return;
            }

            // A server shift this till closed offline stays closed, even
            // though the cached snapshot still lists it until the next sync.
            const closedHere = offline?.closed_at && offline.id && offline.id === this.snapshot.shift?.id;

            this.shift = this.snapshot.shift && !closedHere
                ? { id: this.snapshot.shift.id, opened_at: this.snapshot.shift.opened_at, opening_cash: this.snapshot.shift.opening_cash, moves: [], offline: false }
                : null;
        },

        async openShift() {
            this.shiftError = '';

            const opening = parseMoney(this.openingCash);
            const shift = { key: ulid(), opened_at: new Date().toISOString(), opening_cash: opening, moves: [], offline: true, closed_at: null };

            await enqueue('shift.opened', { type: 'shift.opened', client_shift_key: shift.key, opening_cash: opening });
            await put(META, shift, SHIFT_KEY);

            this.shift = shift;
            this.openingCash = '';
            this.toast(this.labels.shift_opened.replace(':amount', formatMoney(opening)));
        },

        async moveCash() {
            this.shiftError = '';

            const amount = parseMoney(this.moveAmount);

            if (!this.shift) {
                this.shiftError = this.labels.shift_required;

                return;
            }

            if (amount <= 0 || this.moveReason.trim() === '') {
                this.shiftError = this.labels.move_invalid;

                return;
            }

            await enqueue('cash.moved', { ...this.shiftReference(), direction: this.moveDirection, amount, reason: this.moveReason.trim() });

            this.shift.moves = [...(this.shift.moves || []), { direction: this.moveDirection, amount, reason: this.moveReason.trim(), at: new Date().toISOString() }];
            await put(META, JSON.parse(JSON.stringify(this.shift)), SHIFT_KEY);

            this.moveAmount = '';
            this.moveReason = '';
            this.toast(this.labels.move_saved);
        },

        /**
         * Cash this till took while offline in the current shift, plus the
         * moves. Provisional: the server adds what was taken online.
         */
        get offlineCash() {
            if (!this.shift) {
                return 0;
            }

            const ref = JSON.stringify(this.shiftReference());
            const sales = this.bills
                .filter((bill) => bill.status === 'paid' && JSON.stringify(bill.shift ?? {}) === ref)
                .reduce((sum, bill) => sum + bill.payment.amount, 0);
            const moves = (this.shift.moves || []).reduce((sum, move) => sum + (move.direction === 'in' ? move.amount : -move.amount), 0);

            return sales + moves;
        },

        async closeShift() {
            this.shiftError = '';

            if (!this.shift) {
                return;
            }

            if (this.countedCash === '') {
                this.shiftError = this.labels.count_required;

                return;
            }

            await enqueue('shift.closed', { type: 'shift.closed', ...this.shiftReference(), counted_cash: parseMoney(this.countedCash), note: this.shiftNote.trim() || null });

            await put(META, { ...JSON.parse(JSON.stringify(this.shift)), closed_at: new Date().toISOString() }, SHIFT_KEY);

            this.shift = null;
            this.countedCash = '';
            this.shiftNote = '';
            this.shiftPanel = false;
            this.toast(this.labels.shift_closed);

            if (this.online) {
                this.sync();
            }
        },

        // Misc ----------------------------------------------------------

        toast(text) {
            this.toastText = text;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => {
                this.toastText = '';
            }, 3200);
        },

        time(iso) {
            return iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
        },

        typeLabel(type) {
            return this.labels.types[type] ?? type;
        },
    };
}

/**
 * Hand the online till's cart to the offline one. Called when the line is
 * down and the cashier presses save or pay on the online screen.
 */
export async function handOff(wire) {
    const lines = (wire.cart || []).map((line) => ({
        key: ulid(),
        product_id: line.product_id,
        variant_id: line.product_variant_id,
        product_name: line.product_name,
        variant_name: line.variant_name,
        unit_price: line.unit_price,
        modifier_total: line.modifier_total,
        modifiers: (line.modifiers || []).map((m) => ({ id: m.modifier_id, name: m.modifier_name, group: m.group_name, price: m.price })),
        chosen: line.chosen || {},
        quantity: line.quantity,
        note: line.note || null,
    }));

    await put(META, {
        lines,
        orderType: wire.orderType,
        tableId: wire.tableId,
        guestCount: wire.guestCount,
        customerName: wire.customerName,
    }, HANDOFF_KEY);
}
