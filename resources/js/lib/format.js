const MINUTE = 60;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

export function toDate(value) {
    if (!value) {
        return null;
    }
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

/** `12 Oct 2026` */
export function shortDate(value) {
    const date = toDate(value);
    return date ? date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
}

/** `12 Oct, 14:02` (the year only when it is not this year). */
export function dateTime(value, now = new Date()) {
    const date = toDate(value);
    if (!date) {
        return '';
    }
    const day = date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
    return `${day}, ${date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
}

/** `4 h ago`, `in 6 d`, `just now`; past two months, a date. */
export function timeAgo(value, now = new Date()) {
    const date = toDate(value);
    if (!date) {
        return '';
    }
    const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const abs = Math.abs(seconds);
    if (abs < MINUTE) {
        return 'just now';
    }
    const [amount, unit] = abs < HOUR ? [Math.round(abs / MINUTE), 'min'] : abs < DAY ? [Math.round(abs / HOUR), 'h'] : abs < 60 * DAY ? [Math.round(abs / DAY), 'd'] : [null, null];
    if (amount === null) {
        return shortDate(date);
    }
    return seconds < 0 ? `${amount} ${unit} ago` : `in ${amount} ${unit}`;
}

/** Whole days since a date (for queue ages). */
export function daysSince(value, now = new Date()) {
    const date = toDate(value);
    return date ? Math.floor((now.getTime() - date.getTime()) / (DAY * 1000)) : 0;
}

/** Minor units to a currency string: `14900, 'USD'` → `$149`, `$149.50`. */
export function money(amount, currency = 'USD') {
    const value = (Number(amount) || 0) / 100;
    try {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency, minimumFractionDigits: Number.isInteger(value) ? 0 : 2 }).format(value);
    } catch {
        return `${currency} ${value.toFixed(2)}`;
    }
}

/** The price as cards show it: `Free`, `$149`, the sale price when on sale. */
export function priceLabel(price) {
    if (!price || price.model === 'free' || !price.amount) {
        return 'Free';
    }
    return money(price.effective_amount ?? price.amount, price.currency);
}

export function isPaid(price) {
    return Boolean(price && price.model !== 'free' && price.amount);
}

export function bytes(value) {
    const n = Number(value) || 0;
    if (n < 1024) {
        return `${n} B`;
    }
    const units = ['KB', 'MB', 'GB'];
    let size = n / 1024;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }
    return `${size.toFixed(size < 10 ? 1 : 0)} ${units[unit]}`;
}

export function initials(name) {
    return (
        String(name ?? '')
            .split(/[\s_-]+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0].toUpperCase())
            .join('') || '?'
    );
}

export function humanize(value) {
    const text = String(value ?? '').replace(/[_-]+/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function plural(count, one, many = `${one}s`) {
    return `${count} ${count === 1 ? one : many}`;
}

/** A stable icon gradient for an extension without an icon image. */
export function iconTone(seed) {
    const tones = ['i-blue', 'i-teal', 'i-amber', 'i-violet', 'i-slate', 'i-rose'];
    let hash = 0;
    for (const char of String(seed ?? '')) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }
    return tones[hash % tones.length];
}
