import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';

const { router, page, api } = vi.hoisted(() => ({
    router: { replace: vi.fn(), reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => {}) },
    page: { props: {} },
    api: { patch: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), upload: vi.fn() },
}));

// Whole pages render in jsdom; give them room when the machine is busy.
vi.setConfig({ testTimeout: 30000 });

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => page,
    usePoll: () => ({ start: () => {}, stop: () => {} }),
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));
vi.mock('../../layouts/SiteLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../lib/api', () => ({ api }));

import StudioIndex from '../../pages/Studio/Index';
import StudioEditor, { changes, toDraft } from '../../pages/Studio/Editor';

const account = {
    organizations: [
        { id: 'o1', slug: 'acme', name: 'Acme Logistics', role: 'owner', is_personal: false },
        { id: 'o2', slug: 'pat', name: 'Pat Publisher', role: 'publisher', is_personal: true },
        { id: 'o3', slug: 'viewer', name: 'Viewer Org', role: 'member', is_personal: false },
    ],
};
const rel = (id, version, status, extra = {}) => ({ id, version, status, channel: 'stable', created_at: '2026-10-01T00:00:00Z', published_at: null, ...extra });
const ext = (slug, org, status, releases, missing = []) => ({ id: slug, slug, name: slug, status, organization: { id: org, slug: org, name: org }, price: { model: 'free', amount: 0, currency: 'USD' }, releases, readiness: { ready: !missing.length, missing } });

beforeEach(() => {
    page.props = { auth: { account } };
    Object.values(router).forEach((fn) => fn.mockClear?.());
});

describe('Studio home', () => {
    const extensions = [ext('yard', 'acme', 'published', [rel('a', '1.0.0', 'published', { published_at: '2026-09-01T00:00:00Z' }), rel('b', '1.1.0', 'rejected')]), ext('dock', 'pat', 'published', [rel('c', '0.3.0', 'in_review')]), ext('rates', 'acme', 'published', [rel('d', '2.0.0', 'published', { published_at: '2026-09-27T00:00:00Z' })])];

    it('groups the inbox and lists every listing with one action', () => {
        const { container } = render(<StudioIndex extensions={extensions} organization={null} />);
        const yours = container.querySelector('[data-inbox="yours"]');
        const fleetbase = container.querySelector('[data-inbox="fleetbase"]');
        expect(within(yours).getByText('yard v1.1.0: changes requested')).toBeInTheDocument();
        expect(within(fleetbase).getByText('dock v0.3.0 is in review')).toBeInTheDocument();
        expect(container.querySelectorAll('.sd-ext-row')).toHaveLength(3);
        expect(screen.getByRole('link', { name: 'See feedback: yard' })).toHaveAttribute('href', '/studio/yard/review');
        expect(screen.getByRole('link', { name: 'View review: dock' })).toHaveAttribute('href', '/studio/dock/review');
        expect(screen.getByRole('link', { name: 'New release: rates' })).toHaveAttribute('href', '/studio/rates/releases');
        expect(screen.getByText('Up to date. No release in progress.')).toBeInTheDocument();
        expect(screen.getAllByText('Live v1.0.0').length).toBeGreaterThan(0);
    });

    it('filters by the organization in the URL and switches with the menu', () => {
        const { container } = render(<StudioIndex extensions={extensions} organization="pat" />);
        expect(container.querySelectorAll('.sd-ext-row')).toHaveLength(1);
        expect(container.querySelector('[data-inbox="yours"]').querySelectorAll('[data-inbox-item]')).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: /showing pat publisher/i }));
        expect(screen.queryByText('Viewer Org')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /acme logistics/i }));
        expect(router.replace).toHaveBeenCalledWith(expect.objectContaining({ url: '/studio?org=acme' }));
    });

    it('shows the first-run guide without listings, and a note without a publishing organization', () => {
        page.props = { auth: { account: { organizations: [] } } };
        const { container } = render(<StudioIndex extensions={[]} />);
        expect(container.querySelector('[data-first-run]')).toBeInTheDocument();
        expect(screen.getByText(/not an owner, admin or publisher/i)).toBeInTheDocument();
    });
});

describe('listing editor', () => {
    const extension = {
        id: 'e1',
        slug: 'label-printer',
        name: 'Label Printer',
        subtitle: '',
        description: 'ZPL and PDF shipping labels from packing stations.',
        promotional_text: '<p>Print labels.</p>',
        status: 'draft',
        hosting: 'both',
        organization: { id: 'o1', slug: 'acme', name: 'Acme Logistics' },
        category: { id: 'c1', slug: 'warehouse', name: 'Warehouse' },
        tags: ['labels'],
        website_url: 'https://acme.example',
        support_url: 'https://acme.example/support',
        privacy_url: 'https://acme.example/privacy',
        repo_url: null,
        tos_url: null,
        copyright: '© 2026 Acme',
        fa_icon: null,
        icon_url: 'https://cdn.example/icon.png',
        screenshots: [],
        packages: [{ kind: 'npm', name: '@acme/label-printer-engine' }],
        price: { model: 'free', amount: 0, currency: 'USD' },
        readiness: { ready: false, missing: ['The listing needs a subtitle.'] },
    };
    const releases = [rel('r1', '0.1.0', 'uploaded', { checks: [] })];

    it('recomputes readiness from the unsaved draft and saves only what changed', async () => {
        api.patch.mockResolvedValue({ ...extension, subtitle: 'Carrier labels' });
        const { container } = render(<StudioEditor extension={extension} releases={releases} categories={[{ slug: 'warehouse', name: 'Warehouse' }]} />);
        const chip = container.querySelector('[data-readiness] [data-ready]');
        expect(chip).toHaveTextContent('11 of 12');
        expect(container.querySelector('[data-item="subtitle"]').dataset.ok).toBe('false');
        expect(screen.getByRole('button', { name: /submit v0.1.0 for review/i })).toBeDisabled();
        expect(container.querySelector('[data-savebar]')).toBeNull();

        fireEvent.change(screen.getByLabelText('Subtitle'), { target: { value: 'Carrier labels' } });
        expect(container.querySelector('[data-item="subtitle"]').dataset.ok).toBe('true');
        expect(chip).toHaveTextContent('Ready');
        expect(container.querySelector('[data-savebar]')).not.toBeNull();
        // Unsaved: the review sees the saved listing, so submitting waits for Save.
        expect(screen.getByRole('button', { name: /submit v0.1.0 for review/i })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Support URL'), { target: { value: 'not a url' } });
        expect(container.querySelector('[data-item="support_url"]').dataset.ok).toBe('false');
        fireEvent.change(screen.getByLabelText('Support URL'), { target: { value: 'https://acme.example/support' } });

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await screen.findByRole('link', { name: /submit v0.1.0 for review/i });
        expect(api.patch).toHaveBeenCalledWith('/publisher/extensions/label-printer', { subtitle: 'Carrier labels' });
        expect(container.querySelector('[data-savebar]')).toBeNull();
    });

    it('adds and removes tags from the keyboard', () => {
        const { container } = render(<StudioEditor extension={extension} releases={releases} categories={[]} />);
        const input = screen.getByLabelText('Tags');
        fireEvent.change(input, { target: { value: 'zpl' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        fireEvent.change(input, { target: { value: 'printing,' } });
        expect([...container.querySelectorAll('.sd-tag')].map((node) => node.textContent)).toEqual(['labels', 'zpl', 'printing']);
        fireEvent.keyDown(input, { key: 'Backspace' });
        expect(container.querySelectorAll('.sd-tag')).toHaveLength(2);
        fireEvent.click(screen.getByRole('button', { name: 'Remove tag labels' }));
        fireEvent.click(screen.getByRole('button', { name: 'Remove tag zpl' }));
        expect(container.querySelector('[data-item="tags"]').dataset.ok).toBe('false');
    });

    it('sends empty fields as null', () => {
        const saved = toDraft(extension);
        expect(changes({ ...saved, repo_url: '', support_url: '' }, saved)).toEqual({ support_url: null });
        expect(changes({ ...saved, category: '' }, saved)).toEqual({ category: null });
    });
});
