import { businessDaysSince, filterReleases, isFirstRelease, queueAge, reasonTags, sortOldestFirst } from './queue';

// Thursday 22 Oct 2026, 12:00 local time.
const NOW = new Date(2026, 9, 22, 12, 0, 0);
const at = (days, hours = 0) => new Date(NOW.getTime() - days * 86400000 - hours * 3600000).toISOString();

function release(overrides = {}) {
    return {
        id: overrides.id ?? 'r',
        status: 'submitted',
        checks: [],
        submission: { submitted_at: overrides.submitted_at ?? at(1) },
        updated_at: at(0),
        extension: { status: 'published', price: { model: 'free', amount: 0 }, ...overrides.extension },
        ...overrides,
    };
}

describe('queueAge', () => {
    it('shows hours under a day as new', () => {
        expect(queueAge(release({ submitted_at: at(0, 5) }), NOW)).toMatchObject({ label: '5 h', state: 'new', text: 'new' });
        expect(queueAge(release({ submitted_at: at(0, 5) }), NOW).percent).toBeLessThan(10);
    });

    it('is in target up to three business days and over it after', () => {
        expect(queueAge(release({ submitted_at: at(2) }), NOW)).toMatchObject({ label: '2 d', state: 'in', text: 'in target', percent: 67 });
        // Submitted Friday 16 Oct noon: Sat/Sun do not count, so 4 business days by Thursday.
        expect(queueAge(release({ submitted_at: at(6) }), NOW)).toMatchObject({ label: '6 d', state: 'over', text: 'over target', percent: 100 });
    });

    it('does not count the weekend against the target', () => {
        // Friday 16 Oct noon to Tuesday 20 Oct noon: two business days.
        expect(businessDaysSince(at(6), new Date(2026, 9, 20, 12))).toBe(2);
        expect(queueAge(release({ submitted_at: at(6) }), new Date(2026, 9, 20, 12)).state).toBe('in');
    });

    it('falls back to updated_at without a submission time', () => {
        expect(queueAge(release({ submission: { submitted_at: null }, updated_at: at(1) }), NOW).label).toBe('1 d');
    });
});

describe('filterReleases', () => {
    const first = release({ id: 'first', extension: { status: 'draft' } });
    const update = release({ id: 'update' });
    const paid = release({ id: 'paid', extension: { status: 'draft', price: { model: 'one_time', amount: 4900, currency: 'USD' } } });
    const failed = release({ id: 'failed', status: 'failed_checks', checks: [{ check: 'manifest', passed: false }] });
    const all = [first, update, paid, failed];
    const ids = (filters) => filterReleases(all, filters).map((r) => r.id);

    it('treats a release as first when its listing is not published', () => {
        expect(isFirstRelease(first)).toBe(true);
        expect(isFirstRelease(update)).toBe(false);
    });

    it('filters first releases, updates, failed checks and paid', () => {
        expect(ids([])).toEqual(['first', 'update', 'paid', 'failed']);
        expect(ids(['first'])).toEqual(['first', 'paid']);
        expect(ids(['update'])).toEqual(['update', 'failed']);
        expect(ids(['first', 'update'])).toEqual(['first', 'update', 'paid', 'failed']);
        expect(ids(['failed'])).toEqual(['failed']);
        expect(ids(['paid'])).toEqual(['paid']);
        expect(ids(['update', 'paid'])).toEqual([]);
    });
});

describe('sortOldestFirst and reasonTags', () => {
    it('orders by time in the queue', () => {
        const list = [release({ id: 'new', submitted_at: at(0, 2) }), release({ id: 'old', submitted_at: at(4) })];
        expect(sortOldestFirst(list).map((r) => r.id)).toEqual(['old', 'new']);
    });

    it('turns policy reasons into tags', () => {
        expect(reasonTags(release({ review: { reasons: { first_release: '…', pricing: '…' } } }))).toEqual(['Price changed']);
        expect(reasonTags(release({ review: { reasons: [] } }))).toEqual([]);
    });
});
