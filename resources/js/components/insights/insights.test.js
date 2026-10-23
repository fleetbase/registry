import { activePreset, bucketLabel, change, compact, csvHref, eventStatus, eventVersion, niceMax, presetRange, rangeLabel, revenueIn, revenueLine, ticks, versionRows } from './insights';

const today = new Date('2026-10-23T15:00:00Z');

describe('ranges', () => {
    it('builds presets ending today, in UTC days', () => {
        expect(presetRange(30, today)).toEqual({ from: '2026-09-24', to: '2026-10-23' });
        expect(presetRange(7, today)).toEqual({ from: '2026-10-17', to: '2026-10-23' });
    });

    it('recognises a preset range and treats anything else as custom', () => {
        expect(activePreset({ from: '2026-09-24', to: '2026-10-23' }, today)).toBe(30);
        expect(activePreset({ from: '2026-10-01', to: '2026-10-23' }, today)).toBeNull();
        expect(activePreset(undefined, today)).toBeNull();
    });

    it('labels ranges and buckets', () => {
        expect(rangeLabel({ from: '2026-10-01', to: '2026-10-23' })).toBe('1 Oct – 23 Oct 2026');
        expect(rangeLabel({ from: '2025-12-01', to: '2026-01-31' })).toBe('1 Dec 2025 – 31 Jan 2026');
        expect(bucketLabel('2026-10-05', 'day')).toBe('5 Oct');
        expect(bucketLabel('2026-10-05', 'week')).toBe('Week of 5 Oct');
        expect(bucketLabel('2026-10-01', 'month')).toBe('Oct 2026');
    });
});

describe('change against the previous period', () => {
    it('words a rise as a percentage, good or bad by the measure', () => {
        expect(change(41, 36)).toEqual({ text: '▲ 14%', tone: 'good', diff: 5 });
        expect(change(30, 40)).toEqual({ text: '▼ 25%', tone: 'bad', diff: -10 });
        expect(change(3, 1, { goodWhenUp: false, absolute: true })).toEqual({ text: '▲ +2', tone: 'bad', diff: 2 });
    });

    it('counts instead of dividing by zero, and says when nothing changed', () => {
        expect(change(4, 0)).toEqual({ text: '▲ +4', tone: 'good', diff: 4 });
        expect(change(2, 2)).toEqual({ text: 'No change', tone: 'flat', diff: 0 });
        expect(change(120700, 0, { format: (value) => `$${value / 100}` }).text).toBe('▲ +$1207');
    });
});

describe('revenue', () => {
    const revenue = [
        { currency: 'USD', purchases: 2, gross: 10584, refunded: 5292, fee: 490, net: 4802 },
        { currency: 'EUR', purchases: 1, gross: 4000, refunded: 0, fee: 400, net: 3600 },
    ];

    it('picks a currency, zero when it sold nothing there', () => {
        expect(revenueIn(revenue, 'EUR').net).toBe(3600);
        expect(revenueIn(revenue, 'GBP')).toEqual({ currency: 'GBP', purchases: 0, gross: 0, refunded: 0, fee: 0, net: 0 });
    });

    it('lists net per currency', () => {
        expect(revenueLine(revenue)).toBe('$48.02 · €36');
        expect(revenueLine([])).toBe('$0');
    });
});

describe('versions', () => {
    const versions = ['2.1.0', '2.0.0', '1.9.0', '1.8.0', '1.7.0', '1.6.0'].map((version, index) => ({ version, release: `rel_${index}`, instances: 10 - index, share: (10 - index) / 45 }));

    it('folds the tail into one older row', () => {
        const rows = versionRows(versions);
        expect(rows.map((row) => row.label)).toEqual(['v2.1.0', 'v2.0.0', 'v1.9.0', 'v1.8.0', 'v1.7.0 and older']);
        expect(rows[4].instances).toBe(11);
        expect(rows[4].share).toBeCloseTo(11 / 45);
    });

    it('keeps short lists whole and names a missing version', () => {
        expect(versionRows([{ version: null, instances: 2, share: 1 }]).map((row) => row.label)).toEqual(['Unknown version']);
    });
});

describe('axis', () => {
    it('rounds the top of the axis up to a clean number', () => {
        expect(niceMax(0)).toBe(4);
        expect(niceMax(6)).toBe(8);
        expect(niceMax(23)).toBe(40);
        expect(niceMax(90)).toBe(100);
        expect(niceMax(1340)).toBe(2000);
        expect(ticks(40)).toEqual([0, 10, 20, 30, 40]);
        expect(compact(1500)).toBe('1.5K');
        expect(compact(12000)).toBe('12K');
        expect(compact(800)).toBe('800');
    });
});

describe('events', () => {
    it('labels updates, reinstalls and partial refunds', () => {
        expect(eventStatus({ type: 'update', version: '1.1.0', from_version: '1.0.0' }).label).toBe('Updated');
        expect(eventStatus({ type: 'update', version: '1.1.0', from_version: '1.1.0' }).label).toBe('Reinstalled');
        expect(eventStatus({ type: 'refund', partial: true }).label).toBe('Partly refunded');
        expect(eventVersion({ type: 'update', version: '1.1.0', from_version: '1.0.0' })).toBe('v1.0.0 → v1.1.0');
        expect(eventVersion({ type: 'purchase', version: null })).toBe('—');
    });
});

it('points the CSV at the listing or the organization, with the range', () => {
    const range = { from: '2026-10-01', to: '2026-10-23', group: 'week' };
    expect(csvHref({ organization: 'acme', extension: 'route-optimizer', range })).toBe('/v1/publisher/extensions/route-optimizer/stats.csv?from=2026-10-01&to=2026-10-23&group=week');
    expect(csvHref({ organization: 'acme', extension: null, range })).toBe('/v1/organizations/acme/stats.csv?from=2026-10-01&to=2026-10-23&group=week');
});
