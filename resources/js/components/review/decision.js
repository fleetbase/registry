/**
 * The review decision rules, kept pure so they can be tested without rendering.
 *
 * A mark is `'pass'`, `'fail'` or `null` (not checked yet) per requirement code. Approve needs
 * every requirement passed; Request changes needs at least one failed and a message.
 */

/** Requirements the automated checks already answer: code → the check that decides it. */
export const AUTO_ITEMS = { R1: 'manifest', R4: 'secrets_scan' };

/** Statuses a reviewer can approve or reject from. */
export const DECIDABLE_STATUSES = ['submitted', 'in_review'];

export function canDecide(status) {
    return DECIDABLE_STATUSES.includes(status);
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
 * What the decision rail shows and allows for the current marks and message.
 */
export function decisionState({ items = [], marks = {}, reason = '', publishNow = true }) {
    const failed = items.filter((item) => marks[item.code] === 'fail').length;
    const unset = items.filter((item) => !marks[item.code]).length;
    const hasReason = String(reason ?? '').trim().length > 0;
    const canReject = failed > 0 && hasReason;
    const canApprove = items.length > 0 && failed === 0 && unset === 0;

    let hint;
    if (canReject) {
        hint = 'The publisher sees the message and every failed item.';
    } else if (canApprove) {
        hint = 'Everything passes.';
    } else if (failed) {
        hint = 'Add a message to request changes.';
    } else {
        hint = 'Mark every requirement to approve.';
    }

    return {
        failed,
        unset,
        canReject,
        canApprove,
        hint,
        progress: `${failed} failed · ${unset} to check`,
        reasonHint: failed ? 'required to reject' : 'optional',
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
