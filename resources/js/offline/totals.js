/*
 * The bottom of a bill, worked out in the browser for the offline till.
 *
 * A line-for-line copy of App\Domain\Sales\CalculateTotals, including its use
 * of floating point division followed by rounding, so the total a guest pays
 * offline is the same number the server works out when the sale is synced.
 * Change one, change the other.
 */

export function lineGross(line) {
    return Math.max(0, line.unit_price + line.modifier_total) * Math.max(0, line.quantity);
}

function round(value) {
    // PHP rounds halves away from zero; for the positive amounts on a bill
    // that is the same as Math.round.
    return value < 0 ? -Math.round(-value) : Math.round(value);
}

function carveOut(base, profile) {
    if (base <= 0) {
        return [0, 0, Math.max(0, base)];
    }

    const grossFactor = (10000 + profile.service_bps) * (10000 + profile.tax_bps);
    const net = round((base * 10000 * 10000) / grossFactor);
    const serviceCharge = round((net * profile.service_bps) / 10000);

    return [serviceCharge, base - net - serviceCharge, base];
}

function addOn(base, profile) {
    if (base <= 0) {
        return [0, 0, Math.max(0, base)];
    }

    const serviceCharge = round((base * profile.service_bps) / 10000);
    const tax = round(((base + serviceCharge) * profile.tax_bps) / 10000);

    return [serviceCharge, tax, base + serviceCharge + tax];
}

function roundingFor(grand, step) {
    if (step <= 1 || grand <= 0) {
        return 0;
    }

    return round(grand / step) * step - grand;
}

/**
 * @param {Array<{unit_price: number, modifier_total: number, quantity: number}>} lines
 * @param {{tax_bps: number, service_bps: number, price_includes_tax: boolean, cash_rounding: number}} profile
 */
export function calculateTotals(lines, profile) {
    const subtotal = lines.reduce((sum, line) => sum + lineGross(line), 0);
    const base = subtotal;

    const [serviceCharge, tax, grand] = profile.price_includes_tax ? carveOut(base, profile) : addOn(base, profile);
    const rounding = roundingFor(grand, profile.cash_rounding);

    return {
        subtotal,
        discount_total: 0,
        service_charge_total: serviceCharge,
        tax_total: tax,
        rounding,
        grand_total: grand + rounding,
    };
}

/**
 * Round notes a cashier is likely to be handed, above what is owed. The same
 * list the online payment screen offers.
 */
export function quickAmounts(owed) {
    const values = [5000, 10000, 20000, 50000, 100000]
        .map((note) => Math.ceil(owed / note) * note)
        .filter((value) => value > owed);

    return [...new Set(values)].sort((a, b) => a - b).slice(0, 3);
}

export function formatMoney(amount, prefix = true) {
    const sign = amount < 0 ? '-' : '';
    const digits = Math.abs(Math.trunc(amount)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return sign + (prefix ? 'Rp' : '') + digits;
}

export function parseMoney(value) {
    const digits = String(value ?? '').replace(/[^0-9-]/g, '');

    return digits === '' || digits === '-' ? 0 : parseInt(digits, 10);
}
