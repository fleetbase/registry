import { checksSummary } from '../Checks';
import { isPaid, toDate } from '../../lib/format';

/** Fleetbase aims to decide within this many business days of submission. */
export const TARGET_BUSINESS_DAYS = 3;

const HOUR = 60 * 60 * 1000;
const DAY = 24 * HOUR;

/** When the release joined the queue: its submission, else its last change. */
export function queuedAt(release) {
    return release?.submission?.submitted_at || release?.updated_at || release?.created_at || null;
}

/** Whole weekdays elapsed since `value` (each 24 h step that lands on Monday–Friday). */
export function businessDaysSince(value, now = new Date()) {
    const start = toDate(value);
    if (!start) {
        return 0;
    }
    let days = 0;
    for (let at = start.getTime() + DAY; at <= now.getTime(); at += DAY) {
        const weekday = new Date(at).getDay();
        if (weekday !== 0 && weekday !== 6) {
            days++;
        }
    }
    return days;
}

/**
 * The age column: `5 h` or `4 d`, a bar filled against the 3-business-day target, and a word.
 * Under a day it is new (green), within the target amber, past it red.
 */
export function queueAge(release, now = new Date()) {
    const at = toDate(queuedAt(release));
    if (!at) {
        return { label: '–', state: 'new', text: 'new', percent: 0, business: 0 };
    }
    const elapsed = Math.max(0, now.getTime() - at.getTime());
    const label = elapsed < DAY ? `${Math.max(1, Math.floor(elapsed / HOUR))} h` : `${Math.floor(elapsed / DAY)} d`;
    const business = businessDaysSince(at, now);
    const state = business > TARGET_BUSINESS_DAYS ? 'over' : elapsed < DAY ? 'new' : 'in';
    const used = elapsed < DAY ? elapsed / DAY : business;
    const percent = Math.min(100, Math.max(4, Math.round((used / TARGET_BUSINESS_DAYS) * 100)));
    const text = { new: 'new', in: 'in target', over: 'over target' }[state];
    return { label, state, text, percent, business };
}

/**
 * A first release has no live version before it. The admin API says so with
 * `previous_version`; without it, a release whose listing is not live yet counts as first.
 */
export function isFirstRelease(release) {
    if (release?.previous_version) {
        return false;
    }
    return (release?.extension?.status ?? null) !== 'published';
}

/** The queue tag and header label: `Update from v0.2.1`, `First release`, or `Update`. */
export function updateLabel(release) {
    if (release?.previous_version) {
        return `Update from v${release.previous_version}`;
    }
    return isFirstRelease(release) ? 'First release' : 'Update';
}

export function checksFailed(release) {
    return release?.status === 'failed_checks' || checksSummary(release).tone === 'fail';
}

/** Queue filter chips, applied in the browser. */
export const QUEUE_FILTERS = [
    { key: 'first', label: 'First releases' },
    { key: 'update', label: 'Updates' },
    { key: 'failed', label: 'Checks failed' },
    { key: 'paid', label: 'Paid' },
];

/**
 * Filter by the pressed chips. First releases and Updates are alternatives (either matches);
 * Checks failed and Paid narrow further.
 */
export function filterReleases(releases = [], filters = []) {
    const active = new Set(filters);
    return releases.filter((release) => {
        if (active.has('first') !== active.has('update') && isFirstRelease(release) !== active.has('first')) {
            return false;
        }
        if (active.has('failed') && !checksFailed(release)) {
            return false;
        }
        if (active.has('paid') && !isPaid(release?.extension?.price)) {
            return false;
        }
        return true;
    });
}

/** Oldest in the queue first. */
export function sortOldestFirst(releases = []) {
    return [...releases].sort((a, b) => (toDate(queuedAt(a))?.getTime() ?? 0) - (toDate(queuedAt(b))?.getTime() ?? 0));
}

/** Policy reasons as short tags for the queue (first release is its own tag). */
const REASON_TAGS = { pricing: 'Price changed', package_names: 'Packages changed', permissions: 'Permissions changed' };

export function reasonTags(release) {
    const reasons = release?.review?.reasons ?? {};
    return Object.keys(Array.isArray(reasons) ? {} : reasons)
        .map((key) => REASON_TAGS[key])
        .filter(Boolean);
}
