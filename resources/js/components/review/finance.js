const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** `2026-10` → `Oct 2026`. */
export function monthLabel(month) {
    const match = /^(\d{4})-(\d{2})$/.exec(String(month ?? ''));
    const name = match && MONTHS[Number(match[2]) - 1];
    return name ? `${name} ${match[1]}` : String(month ?? '');
}

/** A typed amount (`42.5`, `42,50`, `$42`) in minor units, or null when it is not an amount. */
export function toMinor(text) {
    const cleaned = String(text ?? '')
        .trim()
        .replace(/[^\d.,]/g, '')
        .replace(',', '.');
    if (!/^\d+(\.\d{0,2})?$/.test(cleaned)) {
        return null;
    }
    return Math.round(Number(cleaned) * 100);
}

/** Minor units as a plain amount for an input: `4292` → `42.92`. */
export function toMajor(amount) {
    return ((Number(amount) || 0) / 100).toFixed(2);
}

/**
 * Who gives back what in a refund of `amount`: Fleetbase returns its fee in proportion
 * (`refund_application_fee`), the publisher the rest (`reverse_transfer`).
 */
export function refundSplit(purchase, amount) {
    const gross = Number(purchase?.gross) || 0;
    const fee = Number(purchase?.fee) || 0;
    const value = Math.max(0, Number(amount) || 0);
    const feeShare = gross > 0 ? Math.min(fee, Math.round((fee * value) / gross)) : 0;
    return { fee: feeShare, publisher: value - feeShare };
}

/** What a refund form says is wrong, or null when it can be sent. */
export function refundError(text, refundable, currency) {
    const amount = toMinor(text);
    if (amount === null || amount < 1) {
        return 'Enter the amount to refund, like 49.00.';
    }
    if (amount > refundable) {
        return `At most ${toMajor(refundable)} ${currency} can be refunded.`;
    }
    return null;
}
