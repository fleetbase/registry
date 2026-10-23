/**
 * The review decision rules, kept pure so they can be tested without rendering.
 *
 * A mark is `'pass'`, `'fail'` or `null` (not checked yet) per requirement code. Approve needs
 * every requirement passed; Request changes needs at least one failed and a message.
 */

/** Requirements the automated checks already answer: code → the check that decides it. */
export const AUTO_ITEMS = { R1: 'manifest', R4: 'secrets_scan' };

/** Statuses a reviewer can approve from. */
export const APPROVABLE_STATUSES = ['submitted', 'in_review'];

/**
 * Statuses a reviewer can request changes from (POST …/reject): also an approved release that
 * is not live yet. Once published it can only be yanked by its publisher.
 */
export const CHANGEABLE_STATUSES = [...APPROVABLE_STATUSES, 'approved'];

/** Whether the release can be approved. */
export function canApprove(status) {
    return APPROVABLE_STATUSES.includes(status);
}

/** Whether changes can be requested on the release. */
export function canRequestChanges(status) {
    return CHANGEABLE_STATUSES.includes(status);
}

/** Whether a reviewer has any decision to take on the release (approve or request changes). */
export function canDecide(status) {
    return canApprove(status) || canRequestChanges(status);
}

/** The requirement codes prefilled from passing checks (shown as “from checks”). */
export function autoPassed(release) {
    const passed = new Set((release?.checks ?? []).filter((check) => check.passed && !check.skipped).map((check) => check.check));
    return Object.entries(AUTO_ITEMS)
        .filter(([, check]) => passed.has(check))
        .map(([code]) => code);
}

/** Starting marks: R1 and R4 pass when their checks passed, the rest wait for the reviewer. */
export function initialMarks(release, items = []) {
    const auto = new Set(autoPassed(release));
    return Object.fromEntries(items.map((item) => [item.code, auto.has(item.code) ? 'pass' : null]));
}

/** Clicking Pass or Fail sets it; clicking the pressed one again clears it. */
export function toggleMark(marks, code, value) {
    return { ...marks, [code]: marks[code] === value ? null : value };
}

/**
 * What the decision rail shows and allows for the current marks and message. Pass the
 * release's `status` to apply the status rules: an approved release can only have changes
 * requested (its message is always required), and a decided one allows nothing.
 */
export function decisionState({ items = [], marks = {}, reason = '', publishNow = true, status = null }) {
    const approvable = status === null || canApprove(status);
    const changeable = status === null || canRequestChanges(status);
    const failed = items.filter((item) => marks[item.code] === 'fail').length;
    const unset = items.filter((item) => !marks[item.code]).length;
    const hasReason = String(reason ?? '').trim().length > 0;
    const canReject = changeable && failed > 0 && hasReason;
    const canApproveNow = approvable && items.length > 0 && failed === 0 && unset === 0;

    let hint;
    if (canReject) {
        hint = 'The publisher sees the message and every failed item.';
    } else if (canApproveNow) {
        hint = 'Everything passes.';
    } else if (!changeable) {
        hint = 'This release has been decided.';
    } else if (failed) {
        hint = 'Add a message to request changes.';
    } else if (!approvable) {
        hint = 'Fail the requirements that need work to request changes.';
    } else {
        hint = 'Mark every requirement to approve.';
    }

    return {
        failed,
        unset,
        canReject,
        canApprove: canApproveNow,
        hint,
        progress: approvable ? `${failed} failed · ${unset} to check` : `${failed} failed`,
        reasonHint: !approvable ? 'required' : failed ? 'required to reject' : 'optional',
        rejectLabel: failed ? `Request changes (${failed})` : 'Request changes',
        approveLabel: publishNow ? 'Approve and publish' : 'Approve',
    };
}

/** The `items` for POST …/approve and …/reject: every marked requirement, with its note. */
export function decisionItems(items = [], marks = {}, notes = {}) {
    return items
        .filter((item) => marks[item.code])
        .map((item) => {
            const note = String(notes[item.code] ?? '').trim();
            return { code: item.code, passed: marks[item.code] === 'pass', ...(note ? { note } : {}) };
        });
}
