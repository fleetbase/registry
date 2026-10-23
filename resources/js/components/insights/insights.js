import { money } from '../../lib/format';

/** Range presets above the report, in days ending today. */
export const PRESETS = [
    { days: 7, label: '7 days' },
    { days: 30, label: '30 days' },
    { days: 90, label: '90 days' },
    { days: 365, label: '1 year' },
];

export const GROUPS = [
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
];

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** `YYYY-MM-DD` of a date, in UTC (the report's days are UTC). */
export function isoDay(date) {
    return date.toISOString().slice(0, 10);
}

/** `{from, to}` for the last `days` days, today included. */
export function presetRange(days, today = new Date()) {
    const to = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()));
    const from = new Date(to.getTime() - (days - 1) * 86400000);
    return { from: isoDay(from), to: isoDay(to) };
}

/** The preset a report's range matches (ending today), or null for a custom range. */
export function activePreset(range, today = new Date()) {
    if (!range) {
        return null;
    }
    const preset = PRESETS.find((item) => {
        const expected = presetRange(item.days, today);
        return expected.from === range.from && expected.to === range.to;
    });
    return preset?.days ?? null;
}

function parts(day) {
    const [year, month, date] = String(day).split('-').map(Number);
    return { year, month, date };
}

/** `5 Oct`, `5 Oct 2025` when `withYear`. */
export function dayLabel(day, withYear = false) {
    const { year, month, date } = parts(day);
    return `${date} ${MONTHS[month - 1]}${withYear ? ` ${year}` : ''}`;
}

/** A bucket's label: `5 Oct`, `Week of 5 Oct`, `Oct 2026`. */
export function bucketLabel(day, group) {
    const { year, month } = parts(day);
    if (group === 'month') {
        return `${MONTHS[month - 1]} ${year}`;
    }
    return group === 'week' ? `Week of ${dayLabel(day)}` : dayLabel(day);
}

/** `1 Oct – 23 Oct 2026` */
export function rangeLabel(range) {
    if (!range) {
        return '';
    }
    const sameYear = parts(range.from).year === parts(range.to).year;
    return `${dayLabel(range.from, !sameYear)} – ${dayLabel(range.to, true)}`;
}

/**
 * The change from the previous period, worded and toned for a stat tile. `goodWhenUp` says whether
 * a rise is good news (installs) or bad (uninstalls); `absolute` words it as a count.
 */
export function change(current, previous, { goodWhenUp = true, absolute = false, format = (value) => String(value) } = {}) {
    const diff = (current ?? 0) - (previous ?? 0);
    if (diff === 0) {
        return { text: 'No change', tone: 'flat', diff };
    }
    const up = diff > 0;
    const tone = up === goodWhenUp ? 'good' : 'bad';
    const arrow = up ? '▲' : '▼';
    if (absolute || !previous) {
        return { text: `${arrow} ${up ? '+' : '−'}${format(Math.abs(diff))}`, tone, diff };
    }
    const pct = Math.round((Math.abs(diff) / Math.abs(previous)) * 100);
    return { text: `${arrow} ${pct}%`, tone, diff };
}

/** Revenue in one currency from a report's list, zeros when it sold nothing there. */
export function revenueIn(revenue = [], currency) {
    return revenue.find((line) => line.currency === currency) ?? { currency, purchases: 0, gross: 0, refunded: 0, fee: 0, net: 0 };
}

/** `$1,207 · €36` — net per currency, highest first. */
export function revenueLine(revenue = [], field = 'net') {
    return revenue.length ? revenue.map((line) => money(line[field], line.currency)).join(' · ') : money(0);
}

/**
 * Version rows for the bars: the newest `max - 1` versions and the rest folded into one
 * `X and older` row, so a long tail never crowds the panel.
 */
export function versionRows(versions = [], max = 5) {
    if (versions.length <= max) {
        return versions.map((row) => ({ ...row, label: row.version ? `v${row.version}` : 'Unknown version' }));
    }
    const head = versions.slice(0, max - 1).map((row) => ({ ...row, label: row.version ? `v${row.version}` : 'Unknown version' }));
    const tail = versions.slice(max - 1);
    const folded = tail.reduce((sum, row) => ({ instances: sum.instances + row.instances, share: sum.share + row.share }), { instances: 0, share: 0 });
    return [...head, { ...folded, version: null, release: null, label: `${tail[0].version ? `v${tail[0].version}` : 'Unknown'} and older` }];
}

/**
 * The top of the y axis: `count` equal whole-number steps of 1, 2, 2.5 or 5 × 10ⁿ that reach the
 * largest value (at least 1 per step), so every gridline lands on a clean integer.
 */
export function niceMax(value, count = 4) {
    const raw = Math.max(1, value / count);
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const factors = magnitude >= 10 ? [1, 2, 2.5, 5, 10] : [1, 2, 5, 10];
    return factors.find((factor) => factor * magnitude >= raw) * magnitude * count;
}

/** Evenly spaced ticks from 0 to `max`. */
export function ticks(max, count = 4) {
    return Array.from({ length: count + 1 }, (_, index) => Math.round((max / count) * index));
}

/** `1.2K` from 1,000 up. */
export function compact(value) {
    return value >= 1000 ? `${(value / 1000).toFixed(value >= 10000 ? 0 : 1).replace(/\.0$/, '')}K` : String(value);
}

const EVENTS = {
    install: { label: 'Installed', tone: 'ok', icon: 'download' },
    update: { label: 'Updated', tone: 'wait', icon: 'arrowUp' },
    uninstall: { label: 'Uninstalled', tone: 'fail', icon: 'trash' },
    purchase: { label: 'Purchased', tone: 'wait', icon: 'card' },
    refund: { label: 'Refunded', tone: 'you', icon: 'arrowLeft' },
};

export function eventStatus(event) {
    const status = EVENTS[event.type] ?? { label: event.type, tone: 'idle', icon: 'minus' };
    if (event.type === 'refund' && event.partial) {
        return { ...status, label: 'Partly refunded' };
    }
    if (event.type === 'update' && event.from_version && event.from_version === event.version) {
        return { ...status, label: 'Reinstalled' };
    }
    return status;
}

/** The version column of an event: `v1.1.0`, `v1.0.0 → v1.1.0`. */
export function eventVersion(event) {
    if (!event.version) {
        return '—';
    }
    return event.type === 'update' && event.from_version && event.from_version !== event.version ? `v${event.from_version} → v${event.version}` : `v${event.version}`;
}

/** The CSV of what's on screen: the listing's, or the organization's. */
export function csvHref({ organization, extension, range }) {
    const query = new URLSearchParams(range ? { from: range.from, to: range.to, group: range.group } : {}).toString();
    const base = extension ? `/v1/publisher/extensions/${extension}/stats.csv` : `/v1/organizations/${organization}/stats.csv`;
    return query ? `${base}?${query}` : base;
}
