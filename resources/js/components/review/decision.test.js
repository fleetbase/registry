import { autoPassed, canDecide, decisionItems, decisionState, initialMarks, toggleMark } from './decision';

const ITEMS = ['R1', 'R2', 'R3', 'R4', 'R5', 'R6'].map((code) => ({ code, label: `Item ${code}` }));

function release(checks) {
    return { status: 'in_review', checks: Object.entries(checks).map(([check, passed]) => ({ check, passed, skipped: false })) };
}

describe('initialMarks', () => {
    it('passes R1 and R4 when the manifest and secrets checks passed, and leaves the rest open', () => {
        const marks = initialMarks(release({ manifest: true, secrets_scan: true, compat: true }), ITEMS);
        expect(marks).toEqual({ R1: 'pass', R2: null, R3: null, R4: 'pass', R5: null, R6: null });
        expect(autoPassed(release({ manifest: true, secrets_scan: true }))).toEqual(['R1', 'R4']);
    });

    it('does not prefill from a failed or missing check', () => {
        expect(initialMarks(release({ manifest: false }), ITEMS)).toMatchObject({ R1: null, R4: null });
        expect(initialMarks({ checks: [{ check: 'secrets_scan', passed: true, skipped: true }] }, ITEMS).R4).toBeNull();
        expect(initialMarks({}, ITEMS).R1).toBeNull();
    });
});

describe('toggleMark', () => {
    it('sets a mark and clears it when the pressed one is clicked again', () => {
        const once = toggleMark({ R2: null }, 'R2', 'fail');
        expect(once.R2).toBe('fail');
        expect(toggleMark(once, 'R2', 'fail').R2).toBeNull();
        expect(toggleMark(once, 'R2', 'pass').R2).toBe('pass');
    });
});

describe('decisionState', () => {
    const allPass = Object.fromEntries(ITEMS.map((item) => [item.code, 'pass']));

    it('allows approve only when every requirement passes', () => {
        expect(decisionState({ items: ITEMS, marks: allPass }).canApprove).toBe(true);
        expect(decisionState({ items: ITEMS, marks: { ...allPass, R6: null } })).toMatchObject({ canApprove: false, unset: 1, hint: 'Mark every requirement to approve.' });
        expect(decisionState({ items: ITEMS, marks: { ...allPass, R5: 'fail' } }).canApprove).toBe(false);
        expect(decisionState({ items: [], marks: {} }).canApprove).toBe(false);
    });

    it('allows request changes only with a failed requirement and a message', () => {
        const failed = { ...allPass, R5: 'fail', R2: 'fail' };
        expect(decisionState({ items: ITEMS, marks: failed, reason: '  ' })).toMatchObject({ canReject: false, failed: 2, reasonHint: 'required to reject', hint: 'Add a message to request changes.' });
        expect(decisionState({ items: ITEMS, marks: failed, reason: 'Fix the policies.' })).toMatchObject({ canReject: true, rejectLabel: 'Request changes (2)' });
        expect(decisionState({ items: ITEMS, marks: allPass, reason: 'Looks good' }).canReject).toBe(false);
    });

    it('labels approve by the publish-at-once choice and counts progress', () => {
        expect(decisionState({ items: ITEMS, marks: allPass, publishNow: true }).approveLabel).toBe('Approve and publish');
        expect(decisionState({ items: ITEMS, marks: allPass, publishNow: false }).approveLabel).toBe('Approve');
        expect(decisionState({ items: ITEMS, marks: { R1: 'pass', R5: 'fail' } }).progress).toBe('1 failed · 4 to check');
    });
});

describe('decisionItems', () => {
    it('sends every marked requirement with its trimmed note', () => {
        expect(decisionItems(ITEMS, { R1: 'pass', R5: 'fail', R2: null }, { R5: ' Hard-coded policies ', R1: '' })).toEqual([
            { code: 'R1', passed: true },
            { code: 'R5', passed: false, note: 'Hard-coded policies' },
        ]);
    });
});

describe('canDecide', () => {
    it('only lets reviewers decide submitted and in-review releases', () => {
        expect(canDecide('submitted')).toBe(true);
        expect(canDecide('in_review')).toBe(true);
        expect(canDecide('approved')).toBe(false);
        expect(canDecide('published')).toBe(false);
    });
});
