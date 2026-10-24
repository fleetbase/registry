import { describe, expect, it } from 'vitest';
import { groupInbox, listingState, summarizeMissing } from './inbox';

const release = (id, version, status, created_at = '2026-10-01T00:00:00Z', extra = {}) => ({ id, version, status, channel: 'stable', created_at, published_at: null, ...extra });
const listing = (slug, status, releases = [], missing = []) => ({ id: slug, slug, name: slug.replace(/-/g, ' '), status, releases, readiness: { ready: missing.length === 0, missing } });

describe('groupInbox', () => {
    it('puts rejected, failed, ready and incomplete listings under your move, and reviews with Fleetbase', () => {
        const extensions = [
            listing('yard-board', 'published', [release('a', '1.0.2', 'published', '2026-09-01T00:00:00Z', { published_at: '2026-09-02T00:00:00Z' }), release('b', '1.1.0', 'rejected', '2026-10-05T00:00:00Z')]),
            listing('label-printer', 'draft', [release('c', '0.1.0', 'uploaded')], ['The listing needs a valid support URL.', 'The listing needs an icon.']),
            listing('dock-scheduler', 'published', [release('d', '0.3.0', 'in_review')]),
            listing('carrier-rates', 'published', [release('e', '2.0.0', 'published')]),
            listing('fuel-cards', 'draft', [release('f', '0.1.0', 'failed_checks')]),
            listing('route-plans', 'draft', [], ['The listing needs a subtitle.']),
            listing('scanner', 'published', [release('g', '1.0.0', 'uploaded')]),
            listing('weigh', 'published', [release('h', '3.0.0', 'approved')]),
            listing('totes', 'published', [release('i', '1.0.0', 'submitted')]),
        ];
        const { yours, fleetbase } = groupInbox(extensions);

        expect(yours.map((item) => [item.extension.slug, item.kind])).toEqual([
            ['yard-board', 'rejected'],
            ['label-printer', 'incomplete'],
            ['fuel-cards', 'failed_checks'],
            ['route-plans', 'incomplete'],
            ['scanner', 'ready'],
        ]);
        expect(fleetbase.map((item) => [item.extension.slug, item.kind])).toEqual([
            ['dock-scheduler', 'in_review'],
            ['weigh', 'approved'],
            ['totes', 'submitted'],
        ]);

        expect(yours[0]).toMatchObject({ title: 'yard board v1.1.0: changes requested', href: '/studio/yard-board/review' });
        expect(yours[1]).toMatchObject({ title: 'label printer: listing needs 2 things', detail: 'Support URL, an icon. Then submit v0.1.0.', href: '/studio/label-printer' });
        expect(yours[2].href).toBe('/studio/fuel-cards/releases/f');
        expect(yours[4].href).toBe('/studio/scanner/review');
    });

    it('ignores listings with nothing open and published listings with server readiness', () => {
        expect(groupInbox([listing('carrier-rates', 'published', [release('e', '2.0.0', 'published')])])).toEqual({ yours: [], fleetbase: [] });
        expect(groupInbox([])).toEqual({ yours: [], fleetbase: [] });
    });

    it('uses the newest open release', () => {
        const extension = listing('x', 'published', [release('old', '1.0.0', 'rejected', '2026-09-01T00:00:00Z'), release('new', '1.0.1', 'submitted', '2026-10-01T00:00:00Z')]);
        const { yours, fleetbase } = groupInbox([extension]);
        expect(yours).toEqual([]);
        expect(fleetbase[0].release.id).toBe('new');
    });
});

describe('listingState', () => {
    it('chooses one action per listing', () => {
        expect(listingState(listing('a', 'published', [release('r', '1.0.0', 'rejected')])).action.label).toBe('See feedback');
        expect(listingState(listing('a', 'draft', [release('r', '1.0.0', 'failed_checks')])).action).toMatchObject({ label: 'Fix checks', href: '/studio/a/releases/r' });
        expect(listingState(listing('a', 'draft', [release('r', '1.0.0', 'uploaded')], ['x'])).action.label).toBe('Finish listing');
        expect(listingState(listing('a', 'published', [release('r', '1.0.0', 'in_review')])).action).toMatchObject({ label: 'View review', primary: false });
        expect(listingState(listing('a', 'published', [release('r', '1.0.0', 'uploaded')])).action).toMatchObject({ label: 'View review', primary: true });
        expect(listingState(listing('a', 'published', [release('r', '1.0.0', 'published', undefined, { published_at: '2026-10-01T00:00:00Z' })])).action).toMatchObject({ label: 'New release', href: '/studio/a/releases' });
    });

    it('finds the live version', () => {
        const state = listingState(listing('a', 'published', [release('r1', '1.0.0', 'published', '2026-01-01T00:00:00Z', { published_at: '2026-01-02T00:00:00Z' }), release('r2', '1.1.0', 'published', '2026-02-01T00:00:00Z', { published_at: '2026-02-02T00:00:00Z' })]));
        expect(state.live.version).toBe('1.1.0');
        expect(state.open).toBeNull();
    });
});

describe('summarizeMissing', () => {
    it('shortens the server’s messages', () => {
        expect(summarizeMissing(['The listing needs a subtitle.', 'The listing needs at least one tag.', 'The listing needs a valid website URL.', 'The listing needs an icon.'])).toBe('A subtitle, one tag, website URL, and 1 more.');
        expect(summarizeMissing([])).toBe('');
    });
});
