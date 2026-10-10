<!DOCTYPE html>
{{--
| The offline till.
|
| A plain page, not a Livewire one. The service worker keeps a copy of it, and
| when the connection is gone this is what opens: everything on it runs from
| the menu snapshot and the outbox kept in the browser. The same pos-* styles
| as the online till, so a cashier sees the same screen with an amber badge.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('offline.title')])
        <meta name="meja-app" content="offline">
    </head>
    <body class="min-h-screen bg-canvas text-content">
        <div
            x-data="offlineTerminal(@js(\App\Support\Labels::group('offline')))"
            class="offline-app"
            data-test="offline-terminal"
        >
            {{-- Top bar: where am I, is the line up, is anything waiting. --}}
            <header class="offline-bar">
                <div class="offline-brand">
                    <span class="offline-logo"><x-app-logo-icon class="size-5" /></span>
                    <span class="font-display text-lg font-bold">{{ config('app.name') }}</span>
                </div>

                <span
                    class="conn-badge"
                    :class="online ? (outbox.count > 0 ? 'conn-syncing' : 'conn-online') : 'conn-offline'"
                    role="status"
                    aria-live="polite"
                >
                    <span class="conn-dot" aria-hidden="true"></span>
                    <span x-text="online ? (outbox.count > 0 ? labels.waiting.replace(':count', outbox.count) : labels.online) : labels.mode"></span>
                </span>

                <span class="offline-bar-spacer"></span>

                <button type="button" class="offline-chip" @click="shiftPanel = true" x-show="state === 'ready'" data-test="offline-shift">
                    <flux:icon.banknotes variant="mini" />
                    <span x-text="shift ? labels.shift_since.replace(':time', time(shift.opened_at)) : labels.no_shift"></span>
                </button>

                <button type="button" class="offline-chip" @click="billsPanel = true" x-show="state === 'ready'" data-test="offline-bills">
                    <flux:icon.receipt-percent variant="mini" />
                    <span>{{ __('offline.bills') }}</span>
                    <span class="offline-count" x-show="openBills.length > 0" x-text="openBills.length"></span>
                </button>

                <button type="button" class="offline-chip offline-chip-primary" x-show="online" @click="backOnline()" data-test="back-online">
                    <flux:icon.arrow-path variant="mini" x-bind:class="syncing && 'animate-spin'" />
                    <span>{{ __('offline.back_online') }}</span>
                </button>
            </header>

            {{-- What the cashier needs to know right now, one line each. --}}
            <div class="offline-notices">
                <p class="offline-notice offline-notice-pending" x-show="!online" x-cloak>
                    <flux:icon.signal-slash variant="mini" /> <span>{{ __('offline.offline_notice') }}</span>
                </p>
                <p class="offline-notice offline-notice-done" x-show="online && outbox.count > 0 && !outbox.blocked" x-cloak>
                    <flux:icon.arrow-up-tray variant="mini" /> <span>{{ __('offline.online_notice') }}</span>
                </p>
                <p class="offline-notice offline-notice-danger" x-show="outbox.blocked" x-cloak>
                    <flux:icon.exclamation-triangle variant="mini" />
                    <span>
                        <span x-text="labels.blocked.replace(':message', outbox.blocked?.message || '')"></span>
                        <span class="block text-sm opacity-80">{{ __('offline.blocked_hint') }}</span>
                    </span>
                </p>
                <p class="offline-notice offline-notice-muted" x-show="state === 'ready' && snapshot" x-cloak>
                    <flux:icon.information-circle variant="mini" />
                    <span>
                        <span x-text="labels.last_update.replace(':time', time(snapshot?.generated_at))"></span>
                        &middot; {{ __('offline.no_discount') }}
                    </span>
                </p>
            </div>

            <div x-show="state === 'loading'" class="offline-empty">
                <div class="offline-skeleton motion-shimmer" aria-hidden="true"></div>
                <p class="text-content-muted">{{ __('offline.loading') }}</p>
            </div>

            <div x-show="state === 'empty'" x-cloak class="offline-empty">
                <x-empty-state
                    icon="cloud-arrow-down"
                    :heading="__('offline.no_snapshot_heading')"
                    :description="__('offline.no_snapshot')"
                />
            </div>

            {{-- The till: the same three areas as online. --}}
            <div class="pos-shell offline-shell" x-show="state === 'ready'" x-cloak>
                <section class="pos-categories" aria-label="{{ __('pos.categories') }}">
                    <div class="pos-category-list">
                        <button type="button" class="pos-chip" :class="categoryId === '' && 'pos-chip-on'" @click="categoryId = ''">
                            {{ __('offline.all') }}
                        </button>

                        <template x-for="category in categories" :key="category.id">
                            <button type="button" class="pos-chip" :class="categoryId === category.id && 'pos-chip-on'" @click="categoryId = category.id">
                                <span x-text="category.name"></span>
                            </button>
                        </template>
                    </div>
                </section>

                <section class="pos-menu" aria-label="{{ __('pos.menu') }}">
                    <label class="offline-search">
                        <flux:icon.magnifying-glass variant="mini" class="text-content-muted" />
                        <span class="sr-only">{{ __('offline.search') }}</span>
                        <input type="search" x-model.debounce.120ms="search" placeholder="{{ __('offline.search') }}" data-test="offline-search">
                    </label>

                    <div class="pos-grid">
                        <template x-for="product in products" :key="product.id">
                            <button type="button" class="pos-card" @click="tapProduct(product)" data-test="offline-card">
                                <span class="pos-card-name" x-text="product.name"></span>
                                <span class="pos-card-price numeric tabular-nums" x-text="money(priceOf(product))"></span>
                            </button>
                        </template>
                    </div>
                </section>

                {{-- The cart. A sheet on a phone, a column on anything wider. --}}
                <section class="pos-cart" :class="cartOpen && 'pos-cart-open'" aria-label="{{ __('offline.cart') }}">
                    <button type="button" class="pos-cart-bar" @click="cartOpen = !cartOpen" :aria-expanded="cartOpen">
                        <span class="pos-cart-handle" aria-hidden="true"></span>
                        <span class="pos-cart-bar-count" x-text="labels.items.replace(':count', itemCount)"></span>
                        <span class="pos-cart-bar-total numeric tabular-nums" x-text="money(totals.grand_total)"></span>
                    </button>

                    <div class="pos-cart-body">
                        <div class="offline-types" role="radiogroup" aria-label="{{ __('pos.type') }}">
                            <template x-for="type in ['dine_in', 'take_away', 'delivery']" :key="type">
                                <button
                                    type="button"
                                    role="radio"
                                    class="offline-type"
                                    :class="orderType === type && 'offline-type-on'"
                                    :aria-checked="orderType === type"
                                    @click="setOrderType(type)"
                                    x-text="typeLabel(type)"
                                ></button>
                            </template>
                        </div>

                        <label class="offline-field" x-show="needsTable">
                            <span>{{ __('offline.table') }}</span>
                            <select x-model="tableId" data-test="offline-table">
                                <option value="">{{ __('offline.choose_table') }}</option>
                                <template x-for="table in snapshot?.tables ?? []" :key="table.id">
                                    <option :value="table.id" x-text="table.area ? `${table.name} · ${table.area}` : table.name"></option>
                                </template>
                            </select>
                        </label>

                        <div class="offline-row">
                            <label class="offline-field">
                                <span>{{ __('offline.customer') }}</span>
                                <input type="text" x-model="customerName" maxlength="255">
                            </label>
                            <label class="offline-field offline-field-narrow">
                                <span>{{ __('offline.guests') }}</span>
                                <input type="number" min="1" max="99" x-model.number="guestCount" inputmode="numeric">
                            </label>
                        </div>

                        <ul class="pos-lines">
                            <template x-for="(line, index) in cart" :key="line.key">
                                <li class="pos-line motion-line">
                                    <div class="pos-line-head">
                                        <div class="pos-line-name">
                                            <span x-text="line.product_name"></span>
                                            <span class="pos-line-variant" x-show="line.variant_name" x-text="line.variant_name"></span>
                                        </div>
                                        <span class="pos-line-total numeric tabular-nums" x-text="money(lineTotal(line))"></span>
                                    </div>

                                    <template x-for="modifier in line.modifiers" :key="modifier.id">
                                        <p class="pos-line-modifier">
                                            <span x-text="modifier.name"></span>
                                            <span class="numeric tabular-nums" x-show="modifier.price > 0" x-text="money(modifier.price)"></span>
                                        </p>
                                    </template>

                                    <p class="pos-line-note" x-show="line.note" x-text="line.note"></p>

                                    <div class="pos-line-actions">
                                        <button type="button" class="offline-icon-button" @click="decrease(index)" aria-label="{{ __('offline.decrease') }}">
                                            <flux:icon.minus variant="mini" />
                                        </button>
                                        <span class="pos-line-qty" x-text="line.quantity"></span>
                                        <button type="button" class="offline-icon-button" @click="increase(index)" aria-label="{{ __('offline.increase') }}">
                                            <flux:icon.plus variant="mini" />
                                        </button>
                                        <span class="flex-1"></span>
                                        <button type="button" class="offline-icon-button" x-show="productFor(line)" @click="openSheet(productFor(line), index)" aria-label="{{ __('offline.edit') }}">
                                            <flux:icon.pencil-square variant="mini" />
                                        </button>
                                        <button type="button" class="offline-icon-button" @click="removeLine(index)" aria-label="{{ __('offline.remove') }}">
                                            <flux:icon.trash variant="mini" />
                                        </button>
                                    </div>
                                </li>
                            </template>
                        </ul>

                        <div x-show="cart.length === 0">
                            <x-empty-state
                                icon="shopping-bag"
                                :heading="__('offline.cart_empty')"
                                :description="__('offline.cart_empty_description')"
                                class="pos-cart-empty"
                            />
                        </div>

                        <dl class="pos-summary">
                            <div class="pos-summary-row">
                                <dt>{{ __('offline.subtotal') }}</dt>
                                <dd class="numeric tabular-nums" x-text="money(totals.subtotal)"></dd>
                            </div>
                            <div class="pos-summary-row" x-show="totals.service_charge_total > 0">
                                <dt>{{ __('offline.service') }} <span x-text="`${snapshot?.outlet.service_percent}%`"></span></dt>
                                <dd class="numeric tabular-nums" x-text="money(totals.service_charge_total)"></dd>
                            </div>
                            <div class="pos-summary-row" x-show="totals.tax_total > 0">
                                <dt>{{ __('offline.tax') }} <span x-text="`${snapshot?.outlet.tax_percent}%`"></span></dt>
                                <dd class="numeric tabular-nums" x-text="money(totals.tax_total)"></dd>
                            </div>
                            <div class="pos-summary-row" x-show="totals.rounding !== 0">
                                <dt>{{ __('offline.rounding') }}</dt>
                                <dd class="numeric tabular-nums" x-text="money(totals.rounding)"></dd>
                            </div>
                            <div class="pos-summary-row pos-summary-grand">
                                <dt>{{ __('offline.total') }}</dt>
                                <dd class="numeric tabular-nums" x-text="money(totals.grand_total)" data-test="offline-total"></dd>
                            </div>
                        </dl>

                        <p class="offline-error" x-show="error" x-text="error" role="alert"></p>

                        <div class="pos-actions">
                            <button type="button" class="offline-button offline-button-subtle pos-action" :disabled="cart.length === 0" @click="holdBill()" data-test="offline-hold">
                                {{ __('offline.hold') }}
                            </button>
                            <button type="button" class="offline-button offline-button-primary pos-action" :disabled="cart.length === 0" @click="checkout()" data-test="offline-pay">
                                {{ __('offline.pay_cash') }}
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Modifier sheet. --}}
            <template x-if="sheet">
                <div class="pos-sheet-backdrop" @click.self="closeSheet()" @keydown.escape.window="closeSheet()">
                    <div x-trap.noscroll="true" class="pos-sheet" role="dialog" aria-modal="true" aria-labelledby="offline-sheet-title">
                        <span class="pos-sheet-handle" aria-hidden="true"></span>
                        <h2 id="offline-sheet-title" class="text-lg font-semibold" x-text="sheet.product.name"></h2>

                        <fieldset class="pos-choice-group" x-show="sheet.product.variants.length > 1">
                            <legend class="pos-choice-legend">{{ __('offline.variant') }}</legend>
                            <div class="pos-choices">
                                <template x-for="variant in sheet.product.variants" :key="variant.id">
                                    <button type="button" class="pos-choice" :class="sheet.variantId === variant.id && 'pos-choice-on'" @click="sheet.variantId = variant.id">
                                        <span x-text="variant.name"></span>
                                        <span class="numeric tabular-nums" x-text="money(variant.price)"></span>
                                    </button>
                                </template>
                            </div>
                        </fieldset>

                        <template x-for="group in sheet.product.groups" :key="group.id">
                            <fieldset class="pos-choice-group">
                                <legend class="pos-choice-legend">
                                    <span x-text="group.name"></span>
                                    <span class="pos-choice-rule" x-text="group.min > 0 ? (group.min === group.max ? labels.choose_exactly : labels.choose_at_least).replace(':count', group.min) : labels.optional"></span>
                                </legend>
                                <div class="pos-choices">
                                    <template x-for="modifier in group.modifiers" :key="modifier.id">
                                        <button type="button" class="pos-choice" :class="isChosen(group, modifier) && 'pos-choice-on'" @click="toggleModifier(group, modifier)">
                                            <span x-text="modifier.name"></span>
                                            <span class="numeric tabular-nums" x-show="modifier.price > 0" x-text="money(modifier.price)"></span>
                                        </button>
                                    </template>
                                </div>
                            </fieldset>
                        </template>

                        <label class="offline-field">
                            <span>{{ __('offline.note') }}</span>
                            <input type="text" x-model="sheet.note" placeholder="{{ __('offline.note_placeholder') }}">
                        </label>

                        <p class="offline-error" x-show="sheetError" x-text="sheetError" role="alert"></p>

                        <div class="pos-sheet-actions">
                            <button type="button" class="offline-button offline-button-subtle pos-action" @click="closeSheet()">{{ __('offline.cancel') }}</button>
                            <button type="button" class="offline-button offline-button-primary pos-action" @click="confirmSheet()">
                                <span>{{ __('offline.add') }}</span>
                                <span class="numeric tabular-nums" x-text="money(sheetPrice)"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Cash payment. --}}
            <template x-if="payingBill">
                <div class="pos-sheet-backdrop" @click.self="cancelPayment()" @keydown.escape.window="cancelPayment()">
                    <div x-trap.noscroll="true" class="pos-sheet" role="dialog" aria-modal="true" aria-labelledby="offline-pay-title" data-test="offline-payment">
                        <span class="pos-sheet-handle" aria-hidden="true"></span>
                        <h2 id="offline-pay-title" class="text-lg font-semibold">{{ __('offline.payment') }}</h2>
                        <p class="font-mono text-sm text-content-muted" x-text="payingBill.number"></p>

                        <div class="offline-due">
                            <span>{{ __('offline.due') }}</span>
                            <strong class="numeric tabular-nums font-display" x-text="money(payingBill.totals.grand_total)"></strong>
                        </div>

                        <label class="offline-field">
                            <span>{{ __('offline.tendered') }}</span>
                            <input type="text" inputmode="numeric" x-model="tendered" class="offline-amount" data-test="offline-tendered" x-init="$nextTick(() => $el.select())">
                        </label>

                        <div class="pay-quick">
                            <button type="button" class="offline-button offline-button-subtle" @click="setTendered(payingBill.totals.grand_total)">{{ __('offline.exact') }}</button>
                            <template x-for="amount in quickAmounts" :key="amount">
                                <button type="button" class="offline-button offline-button-subtle numeric tabular-nums" @click="setTendered(amount)" x-text="money(amount).replace('Rp', '')"></button>
                            </template>
                        </div>

                        <div class="pay-change" x-show="change > 0">
                            <span>{{ __('offline.change') }}</span>
                            <span class="numeric tabular-nums" x-text="money(change)"></span>
                        </div>

                        <p class="offline-error" x-show="payError" x-text="payError" role="alert"></p>

                        <div class="pos-sheet-actions">
                            <button type="button" class="offline-button offline-button-subtle pos-action" @click="cancelPayment()">{{ __('offline.cancel') }}</button>
                            <button type="button" class="offline-button offline-button-primary pos-action" @click="confirmPayment()" data-test="offline-confirm-payment">{{ __('offline.confirm_payment') }}</button>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Receipt. The .receipt block is what prints, the rest does not. --}}
            <template x-if="receipt">
                <div class="pos-sheet-backdrop" @keydown.escape.window="closeReceipt()">
                    <div x-trap.noscroll="true" class="pos-sheet offline-receipt-sheet" role="dialog" aria-modal="true" aria-label="{{ __('offline.receipt') }}">
                        <div class="offline-success" aria-hidden="true">
                            <svg viewBox="0 0 52 52" class="offline-check"><circle cx="26" cy="26" r="24" /><path d="M15 27l7 7 15-15" /></svg>
                        </div>

                        <article class="receipt" data-test="offline-receipt">
                            <header class="receipt-head">
                                <h1 class="receipt-title" x-text="snapshot.outlet.receipt_header"></h1>
                                <p x-show="snapshot.outlet.address" x-text="snapshot.outlet.address"></p>
                            </header>

                            <dl class="receipt-meta">
                                <div><dt>{{ __('offline.receipt_number') }}</dt><dd x-text="receipt.number"></dd></div>
                                <div><dt>{{ __('offline.receipt_at') }}</dt><dd x-text="time(receipt.payment?.paid_at ?? receipt.created_at)"></dd></div>
                                <div><dt>{{ __('offline.receipt_cashier') }}</dt><dd x-text="receipt.cashier"></dd></div>
                                <div><dt x-text="typeLabel(receipt.type)"></dt><dd x-text="receipt.table_name ?? '-'"></dd></div>
                            </dl>

                            <ul class="receipt-lines">
                                <template x-for="line in receipt.lines" :key="line.key">
                                    <li>
                                        <p class="receipt-line-name">
                                            <span x-text="`${line.quantity}× ${line.product_name}`"></span>
                                            <span class="numeric tabular-nums" x-text="money(lineTotal(line)).replace('Rp', '')"></span>
                                        </p>
                                        <template x-for="modifier in line.modifiers" :key="modifier.id">
                                            <p class="receipt-line-sub" x-text="`+ ${modifier.name}`"></p>
                                        </template>
                                        <p class="receipt-line-sub" x-show="line.note" x-text="line.note"></p>
                                    </li>
                                </template>
                            </ul>

                            <dl class="receipt-totals">
                                <div><dt>{{ __('offline.subtotal') }}</dt><dd class="numeric tabular-nums" x-text="money(receipt.totals.subtotal).replace('Rp', '')"></dd></div>
                                <div x-show="receipt.totals.service_charge_total > 0"><dt>{{ __('offline.service') }}</dt><dd class="numeric tabular-nums" x-text="money(receipt.totals.service_charge_total).replace('Rp', '')"></dd></div>
                                <div x-show="receipt.totals.tax_total > 0"><dt>{{ __('offline.tax') }}</dt><dd class="numeric tabular-nums" x-text="money(receipt.totals.tax_total).replace('Rp', '')"></dd></div>
                                <div x-show="receipt.totals.rounding !== 0"><dt>{{ __('offline.rounding') }}</dt><dd class="numeric tabular-nums" x-text="money(receipt.totals.rounding).replace('Rp', '')"></dd></div>
                                <div class="receipt-grand"><dt>{{ __('offline.total') }}</dt><dd class="numeric tabular-nums" x-text="money(receipt.totals.grand_total)"></dd></div>
                                <template x-if="receipt.payment">
                                    <div><dt x-text="receipt.payment.method"></dt><dd class="numeric tabular-nums" x-text="money(receipt.payment.tendered).replace('Rp', '')"></dd></div>
                                </template>
                                <template x-if="receipt.payment && receipt.payment.change > 0">
                                    <div><dt>{{ __('offline.change') }}</dt><dd class="numeric tabular-nums" x-text="money(receipt.payment.change).replace('Rp', '')"></dd></div>
                                </template>
                            </dl>

                            <p class="receipt-note" x-show="snapshot.outlet.price_includes_tax">{{ __('offline.tax_included') }}</p>
                            <p class="receipt-note">{{ __('offline.offline_receipt') }}</p>

                            <footer class="receipt-foot">
                                <p x-text="snapshot.outlet.receipt_footer"></p>
                            </footer>
                        </article>

                        <div class="pos-sheet-actions receipt-actions">
                            <button type="button" class="offline-button offline-button-subtle pos-action" @click="printReceipt()" data-test="offline-print">{{ __('offline.print') }}</button>
                            <button type="button" class="offline-button offline-button-primary pos-action" @click="closeReceipt()" data-test="offline-new-order">{{ __('offline.new_order') }}</button>
                        </div>
                    </div>
                </div>
            </template>

            {{-- Bills rung up on this device. --}}
            <template x-if="billsPanel">
                <div class="pos-sheet-backdrop" @click.self="billsPanel = false" @keydown.escape.window="billsPanel = false">
                    <div x-trap.noscroll="true" class="pos-sheet" role="dialog" aria-modal="true" aria-labelledby="offline-bills-title">
                        <span class="pos-sheet-handle" aria-hidden="true"></span>
                        <h2 id="offline-bills-title" class="text-lg font-semibold">{{ __('offline.bills') }}</h2>

                        <p class="text-content-muted" x-show="bills.length === 0">{{ __('offline.bills_empty') }}</p>

                        <ul class="offline-bill-list">
                            <template x-for="bill in bills" :key="bill.client_order_key">
                                <li class="offline-bill">
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm" x-text="bill.number"></p>
                                        <p class="text-sm text-content-muted">
                                            <span x-text="time(bill.created_at)"></span>
                                            &middot; <span x-text="bill.table_name ?? typeLabel(bill.type)"></span>
                                        </p>
                                        <p class="mt-1 flex flex-wrap gap-1">
                                            <span class="offline-tag" :class="bill.status === 'paid' ? 'offline-tag-done' : 'offline-tag-pending'" x-text="bill.status === 'paid' ? labels.bill_paid : labels.bill_open"></span>
                                            <span class="offline-tag" :class="bill.waiting ? 'offline-tag-pending' : 'offline-tag-muted'" x-text="bill.waiting ? labels.bill_waiting : labels.bill_synced"></span>
                                        </p>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        <span class="numeric tabular-nums font-semibold" x-text="money(bill.totals.grand_total)"></span>
                                        <div class="flex gap-2">
                                            <template x-if="bill.status === 'open'">
                                                <button type="button" class="offline-button offline-button-subtle" @click="resumeBill(bill)">{{ __('offline.resume') }}</button>
                                            </template>
                                            <template x-if="bill.status === 'open'">
                                                <button type="button" class="offline-button offline-button-primary" @click="startPayment(bill)">{{ __('offline.pay') }}</button>
                                            </template>
                                            <template x-if="bill.status === 'paid'">
                                                <button type="button" class="offline-button offline-button-subtle" @click="showReceipt(bill)">{{ __('offline.show_receipt') }}</button>
                                            </template>
                                        </div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>

            {{-- Shift: open, cash in and out, close. --}}
            <template x-if="shiftPanel">
                <div class="pos-sheet-backdrop" @click.self="shiftPanel = false" @keydown.escape.window="shiftPanel = false">
                    <div x-trap.noscroll="true" class="pos-sheet" role="dialog" aria-modal="true" aria-labelledby="offline-shift-title">
                        <span class="pos-sheet-handle" aria-hidden="true"></span>
                        <h2 id="offline-shift-title" class="text-lg font-semibold">{{ __('offline.shift') }}</h2>

                        <template x-if="!shift">
                            <div class="grid gap-3">
                                <p class="text-content-muted">{{ __('offline.no_shift_hint') }}</p>
                                <label class="offline-field">
                                    <span>{{ __('offline.opening_cash') }}</span>
                                    <input type="text" inputmode="numeric" x-model="openingCash">
                                </label>
                                <button type="button" class="offline-button offline-button-primary pos-action" @click="openShift()">{{ __('offline.open_shift') }}</button>
                            </div>
                        </template>

                        <template x-if="shift">
                            <div class="grid gap-4">
                                <dl class="shift-summary">
                                    <div><dt>{{ __('offline.opening_cash') }}</dt><dd class="numeric tabular-nums" x-text="money(shift.opening_cash)"></dd></div>
                                    <div><dt>{{ __('offline.offline_cash') }}</dt><dd class="numeric tabular-nums" x-text="money(offlineCash)"></dd></div>
                                </dl>
                                <p class="text-sm text-content-muted">{{ __('offline.provisional') }}</p>

                                <fieldset class="grid gap-2">
                                    <legend class="font-semibold">{{ __('offline.move_cash') }}</legend>
                                    <div class="offline-types">
                                        <button type="button" class="offline-type" :class="moveDirection === 'out' && 'offline-type-on'" @click="moveDirection = 'out'">{{ __('offline.cash_out') }}</button>
                                        <button type="button" class="offline-type" :class="moveDirection === 'in' && 'offline-type-on'" @click="moveDirection = 'in'">{{ __('offline.cash_in') }}</button>
                                    </div>
                                    <div class="offline-row">
                                        <label class="offline-field offline-field-narrow">
                                            <span>{{ __('offline.amount') }}</span>
                                            <input type="text" inputmode="numeric" x-model="moveAmount">
                                        </label>
                                        <label class="offline-field">
                                            <span>{{ __('offline.reason') }}</span>
                                            <input type="text" x-model="moveReason" maxlength="255">
                                        </label>
                                    </div>
                                    <button type="button" class="offline-button offline-button-subtle" @click="moveCash()">{{ __('offline.save_move') }}</button>
                                </fieldset>

                                <fieldset class="grid gap-2">
                                    <legend class="font-semibold">{{ __('offline.close_shift') }}</legend>
                                    <label class="offline-field">
                                        <span>{{ __('offline.counted_cash') }}</span>
                                        <input type="text" inputmode="numeric" x-model="countedCash">
                                    </label>
                                    <label class="offline-field">
                                        <span>{{ __('offline.close_note') }}</span>
                                        <input type="text" x-model="shiftNote" maxlength="500">
                                    </label>
                                    <button type="button" class="offline-button offline-button-primary pos-action" @click="closeShift()">{{ __('offline.close_shift') }}</button>
                                </fieldset>
                            </div>
                        </template>

                        <p class="offline-error" x-show="shiftError" x-text="shiftError" role="alert"></p>
                    </div>
                </div>
            </template>

            <div class="offline-toast" x-show="toastText" x-cloak x-transition.opacity role="status" aria-live="polite" x-text="toastText"></div>
        </div>

        @livewireScripts
        @fluxScripts
    </body>
</html>
