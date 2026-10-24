import { describe, expect, it } from 'vitest';
import { isHttpUrl, readiness, trimmedLength } from './readiness';

const complete = {
    name: 'Label Printer',
    subtitle: 'Carrier labels in one scan',
    description: 'ZPL and PDF shipping labels from packing stations.',
    promotional_text: '<p>Print the right label.</p>',
    tags: ['labels'],
    copyright: '© 2026 Acme',
    website_url: 'https://acme.example',
    support_url: 'https://acme.example/support',
    privacy_url: 'http://acme.example/privacy',
    icon_url: 'https://cdn.example/icon.png',
    fa_icon: '',
    category: 'warehouse',
    status: 'draft',
};

const passed = [{ id: 'r1', version: '0.1.0', status: 'uploaded', created_at: '2026-10-10T09:00:00Z' }];

describe('readiness', () => {
    it('is ready when every listing rule holds and a release passed its checks', () => {
        const result = readiness(complete, passed);
        expect(result.missing).toEqual([]);
        expect(result.listingReady).toBe(true);
        expect(result.ready).toBe(true);
        expect(result.done).toBe(result.total);
        expect(result.items.at(-1)).toMatchObject({ key: 'release', ok: true, label: 'Release v0.1.0 passed checks' });
    });

    it('reports every missing field with the server’s message, in the server’s order', () => {
        const result = readiness({ name: 'Abc', tags: [], status: 'draft' }, []);
        expect(result.missing).toEqual([
            'The listing needs a name longer than 3 characters.',
            'The listing needs a subtitle.',
            'The listing needs a description longer than 12 characters.',
            'The listing needs promotional text.',
            'The listing needs at least one tag.',
            'The listing needs a copyright notice.',
            'The listing needs a valid website URL.',
            'The listing needs a valid support URL.',
            'The listing needs a valid privacy policy URL.',
            'The listing needs an icon.',
            'The listing needs a category.',
        ]);
        expect(result.ready).toBe(false);
    });

    it('applies the server’s thresholds after trimming', () => {
        expect(readiness({ ...complete, name: '  Abcd ' }, passed).missing).toEqual([]);
        expect(readiness({ ...complete, name: ' Abc  ' }, passed).missing).toEqual(['The listing needs a name longer than 3 characters.']);
        expect(readiness({ ...complete, description: '123456789012' }, passed).missing).toEqual(['The listing needs a description longer than 12 characters.']);
        expect(readiness({ ...complete, description: '1234567890123' }, passed).missing).toEqual([]);
        expect(readiness({ ...complete, subtitle: '   ' }, passed).missing).toEqual(['The listing needs a subtitle.']);
    });

    it('accepts a Font Awesome icon name instead of an image', () => {
        expect(readiness({ ...complete, icon_url: null, fa_icon: 'truck' }, passed).missing).toEqual([]);
        expect(readiness({ ...complete, icon_url: null, fa_icon: '' }, passed).missing).toEqual(['The listing needs an icon.']);
    });

    it('accepts the category as a slug or the API object', () => {
        expect(readiness({ ...complete, category: { id: 'cat_1', slug: 'warehouse' } }, passed).listingReady).toBe(true);
        expect(readiness({ ...complete, category: null }, passed).missing).toEqual(['The listing needs a category.']);
    });

    it('needs http(s) URLs', () => {
        expect(isHttpUrl('https://acme.example/x')).toBe(true);
        expect(isHttpUrl('HTTP://acme.example')).toBe(true);
        expect(isHttpUrl('ftp://acme.example')).toBe(false);
        expect(isHttpUrl('acme.example')).toBe(false);
        expect(isHttpUrl('https://')).toBe(false);
        expect(isHttpUrl('https://acme.example/a b')).toBe(false);
        expect(isHttpUrl(null)).toBe(false);
    });

    it('does not re-check published listings', () => {
        const result = readiness({ status: 'published' }, passed);
        expect(result.missing).toEqual([]);
        expect(result.ready).toBe(true);
    });

    it('needs the open release to have passed its checks', () => {
        expect(readiness(complete, []).items.at(-1)).toMatchObject({ ok: false, href: 'releases' });
        const checking = readiness(complete, [{ id: 'r2', version: '0.2.0', status: 'checking', created_at: '2026-10-12T00:00:00Z' }]);
        expect(checking.ready).toBe(false);
        expect(checking.listingReady).toBe(true);
        expect(checking.items.at(-1).label).toBe('Release v0.2.0: checks running');
        expect(readiness(complete, [{ id: 'r3', version: '0.2.0', status: 'failed_checks', created_at: '2026-10-12T00:00:00Z' }]).items.at(-1).label).toBe('Release v0.2.0: checks failed');
        expect(readiness(complete, [{ id: 'r4', version: '0.2.0', status: 'rejected', created_at: '2026-10-12T00:00:00Z' }]).ready).toBe(true);
    });

    it('counts code points like mb_strlen', () => {
        expect(trimmedLength(' 🚚🚚 ')).toBe(2);
        expect(trimmedLength(null)).toBe(0);
    });
});
