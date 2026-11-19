import { daysSince, toDate } from '../../lib/format';

/** Release statuses that are waiting on a Fleetbase reviewer. */
const IN_REVIEW = ['submitted', 'in_review'];

/** When a release (summary or detail) was submitted, or null. */
export function submittedAt(release) {
    return toDate(release?.submitted_at ?? release?.submission?.submitted_at);
}

/** `today`, `yesterday`, `3 days ago`; '' without a date. */
export function daysAgo(value, now = new Date()) {
    if (!toDate(value)) {
        return '';
    }
    const days = Math.max(0, daysSince(value, now));
    return days === 0 ? 'today' : days === 1 ? 'yesterday' : `${days} days ago`;
}

/** `Submitted 2 days ago`; '' when the release has no submission date. */
export function submittedAgo(release, now = new Date()) {
    const at = submittedAt(release);
    return at ? `Submitted ${daysAgo(at, now)}` : '';
}

/** The review's day count as the route shows it (`day 2`): day 1 is the day it was submitted. */
export function reviewDay(release, now = new Date()) {
    const at = submittedAt(release);
    return at ? Math.max(1, daysSince(at, now)) : null;
}

/**
 * The route's stops with the submission dates filled in: the Submitted stop says when
 * (`format` turns the date into its sub-label) and, while a reviewer has it, the Review stop
 * says which day of review it is.
 */
export function withSubmissionDates(stops, release, { now = new Date(), format = (at) => daysAgo(at, now) } = {}) {
    const at = submittedAt(release);
    if (!at) {
        return stops;
    }
    const day = reviewDay(release, now);
    return stops.map((stop) => {
        if (stop.key === 'submitted' && stop.state === 'done') {
            return { ...stop, sub: format(at) };
        }
        if (stop.key === 'review' && IN_REVIEW.includes(release.status)) {
            return { ...stop, sub: `day ${day}` };
        }
        return stop;
    });
}
