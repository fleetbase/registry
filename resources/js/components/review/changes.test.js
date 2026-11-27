import { canonical, compatChanges, manifestChanges, preview, releaseChanges } from './changes';

describe('manifestChanges', () => {
    it('lists added, removed and changed top-level fields, leaving out the version', () => {
        const before = { name: 'geotab', version: '0.2.1', permissions: ['read orders'], icon: 'truck', engine: { a: 1, b: 2 } };
        const after = { name: 'geotab', version: '0.3.0', permissions: ['read orders', 'write orders'], widgets: ['map'], engine: { b: 2, a: 1 } };
        expect(manifestChanges(before, after)).toEqual([
            { key: 'icon', change: 'removed', before: 'truck', after: undefined },
            { key: 'permissions', change: 'changed', before: ['read orders'], after: ['read orders', 'write orders'] },
            { key: 'widgets', change: 'added', before: undefined, after: ['map'] },
        ]);
    });

    it('ignores key and list order, like the review policy', () => {
        expect(canonical({ b: [2, 1], a: 1 })).toBe(canonical({ a: 1, b: [1, 2] }));
        expect(manifestChanges({ permissions: ['b', 'a'] }, { permissions: ['a', 'b'] })).toEqual([]);
        expect(manifestChanges(null, null)).toEqual([]);
    });
});

describe('compatChanges', () => {
    it('reports constraints that differ, with null for a missing side', () => {
        expect(compatChanges({ console: '>=0.7.60' }, { console: '>=0.8.0', api: '>=1.5' })).toEqual([
            { key: 'api', before: null, after: '>=1.5' },
            { key: 'console', before: '>=0.7.60', after: '>=0.8.0' },
        ]);
        expect(compatChanges({ console: '>=1' }, { console: '>=1' })).toEqual([]);
    });
});

describe('releaseChanges', () => {
    it('is null for a first release', () => {
        expect(releaseChanges({ previous: null })).toBeNull();
        expect(releaseChanges({})).toBeNull();
    });

    it('compares a release with the live version it updates', () => {
        const release = {
            version: '0.3.0',
            size_bytes: 2400000,
            compat: { console: '>=0.8.0' },
            manifest: { version: '0.3.0', permissions: ['write'] },
            previous: { version: '0.2.1', published_at: '2026-10-12T14:02:00Z', size_bytes: 2100000, compat: { console: '>=0.7.60' }, manifest: { version: '0.2.1' } },
        };
        expect(releaseChanges(release)).toEqual({
            version: '0.2.1',
            publishedAt: '2026-10-12T14:02:00Z',
            compat: [{ key: 'console', before: '>=0.7.60', after: '>=0.8.0' }],
            manifest: [{ key: 'permissions', change: 'added', before: undefined, after: ['write'] }],
            sizeBefore: 2100000,
            sizeAfter: 2400000,
        });
    });
});

describe('preview', () => {
    it('shows strings as is, other values as JSON, and shortens long ones', () => {
        expect(preview('truck')).toBe('truck');
        expect(preview(['a'])).toBe('["a"]');
        expect(preview(undefined)).toBe('—');
        expect(preview('x'.repeat(200), 10)).toBe(`${'x'.repeat(9)}…`);
    });
});
