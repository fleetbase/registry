import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { vi } from 'vitest';
import Release from '../../pages/Review/Release';
import Queue from '../../pages/Review/Queue';

const { post, reload } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));

vi.mock('../../lib/api', () => ({
    api: { post },
    ApiError: class ApiError extends Error {},
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { account: { id: 'acc_me', is_staff: true } }, flash: null }, url: '/review' }),
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
    router: { reload, visit: vi.fn() },
}));

vi.mock('../../layouts/SiteLayout', async () => {
    const { ToastProvider } = await import('../Toasts');
    return { default: ({ children }) => <ToastProvider>{children}</ToastProvider> };
});

const ITEMS = [
    { code: 'R1', label: 'Manifest is valid' },
    { code: 'R2', label: 'Installs on a clean instance' },
    { code: 'R3', label: 'Listing is complete' },
    { code: 'R4', label: 'No secrets or credentials' },
];

function makeRelease(overrides = {}) {
    return {
        id: 'rel_1',
        version: '1.0.0',
        status: 'in_review',
        channel: 'stable',
        checks: [
            { check: 'manifest', passed: true, warnings: [], errors: [] },
            { check: 'secrets_scan', passed: true, warnings: [], errors: [] },
        ],
        files: { engine: { size: 2400000, original_filename: 'x-engine-1.0.0.tgz' }, api: null, dist: null },
        submission: { submitted_at: new Date().toISOString() },
        review: { required: true, reasons: { first_release: 'This is the extension’s first release.' } },
        reviews: [],
        manifest: { name: 'geotab-bridge' },
        extension: { slug: 'geotab-bridge', name: 'Geotab Bridge', status: 'draft', organization: { slug: 'nw', name: 'Northwind Labs', is_verified: false }, price: { model: 'free', amount: 0 }, tags: [] },
        ...overrides,
    };
}

beforeEach(() => {
    post.mockReset();
    reload.mockReset();
});

describe('Review/Release decision rail', () => {
    it('prefills R1 and R4 from checks and enables approve only when every item passes', async () => {
        post.mockResolvedValue({ status: 'published' });
        render(<Release release={makeRelease()} reviewItems={ITEMS} />);

        const rail = screen.getByRole('region', { name: 'Requirements' });
        expect(within(rail).getByRole('group', { name: 'R1 result' }).querySelector('[aria-pressed="true"]')).toHaveTextContent('Pass');
        expect(within(rail).getByRole('group', { name: 'R4 result' }).querySelector('[aria-pressed="true"]')).toHaveTextContent('Pass');
        expect(within(rail).getAllByText('from checks')).toHaveLength(2);
        expect(screen.getByText(/first release\./)).toBeInTheDocument();

        const approve = within(rail).getByRole('button', { name: 'Approve and publish' });
        expect(approve).toHaveAttribute('aria-disabled', 'true');

        for (const code of ['R2', 'R3']) {
            fireEvent.click(within(within(rail).getByRole('group', { name: `${code} result` })).getByRole('button', { name: 'Pass' }));
        }
        expect(approve).toHaveAttribute('aria-disabled', 'false');

        fireEvent.click(approve);
        fireEvent.click(document.querySelector('[data-confirm-approve]'));

        await waitFor(() => expect(post).toHaveBeenCalledWith('/admin/releases/rel_1/approve', {
            items: ITEMS.map((item) => ({ code: item.code, passed: true })),
            publish: true,
        }));
        await waitFor(() => expect(reload).toHaveBeenCalled());
        expect(await screen.findByText('Geotab Bridge v1.0.0 is live. Instances see it within a minute.')).toBeInTheDocument();
    });

    it('requires a failed item and a message to request changes', async () => {
        post.mockResolvedValue({ status: 'rejected' });
        render(<Release release={makeRelease()} reviewItems={ITEMS} />);
        const rail = screen.getByRole('region', { name: 'Requirements' });

        fireEvent.click(within(within(rail).getByRole('group', { name: 'R3 result' })).getByRole('button', { name: 'Fail' }));
        const reject = within(rail).getByRole('button', { name: 'Request changes (1)' });
        expect(reject).toHaveAttribute('aria-disabled', 'true');

        fireEvent.change(within(rail).getByRole('textbox', { name: /Message to the publisher/ }), { target: { value: 'Add screenshots.' } });
        fireEvent.change(within(rail).getByRole('textbox', { name: 'Note for R3, shown to the publisher' }), { target: { value: 'No screenshots' } });
        expect(reject).toHaveAttribute('aria-disabled', 'false');

        fireEvent.click(reject);
        fireEvent.click(document.querySelector('[data-confirm-reject]'));
        await waitFor(() => expect(post).toHaveBeenCalledWith('/admin/releases/rel_1/reject', {
            reason: 'Add screenshots.',
            items: [
                { code: 'R1', passed: true },
                { code: 'R3', passed: false, note: 'No screenshots' },
                { code: 'R4', passed: true },
            ],
        }));
    });

    it('shows Start review for a submitted release and the decided state once approved', async () => {
        post.mockResolvedValue({});
        const { unmount } = render(<Release release={makeRelease({ status: 'submitted' })} reviewItems={ITEMS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Start review' }));
        await waitFor(() => expect(post).toHaveBeenCalledWith('/admin/releases/rel_1/claim'));
        unmount();

        render(<Release release={makeRelease({ status: 'approved', reviews: [{ action: 'approve', actor: 'staff', by: { id: 'acc_x', name: 'Sam Reviewer' }, items: [], created_at: new Date().toISOString() }] })} reviewItems={ITEMS} />);
        expect(screen.queryByRole('region', { name: 'Requirements' })).not.toBeInTheDocument();
        expect(screen.getByText(/Approved by Sam Reviewer/)).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: /Publish/ }).length).toBeGreaterThan(0);
    });

    it('asks for a reason before suspending the listing', async () => {
        post.mockResolvedValue({});
        render(<Release release={makeRelease()} reviewItems={ITEMS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Suspend listing' }));
        const confirm = document.querySelector('[data-confirm-suspend]');
        fireEvent.click(confirm);
        expect(post).not.toHaveBeenCalled();
        fireEvent.change(screen.getByRole('textbox', { name: 'Reason shown to the publisher' }), { target: { value: 'Leaks phone numbers.' } });
        fireEvent.click(confirm);
        await waitFor(() => expect(post).toHaveBeenCalledWith('/admin/extensions/geotab-bridge/suspend', { reason: 'Leaks phone numbers.' }));
    });
});

describe('Review/Queue', () => {
    const now = Date.now();
    const releases = {
        data: [
            makeRelease({ id: 'rel_new', status: 'submitted', submission: { submitted_at: new Date(now - 3600000 * 5).toISOString() } }),
            makeRelease({ id: 'rel_old', status: 'in_review', submission: { submitted_at: new Date(now - 86400000 * 9).toISOString() }, extension: { slug: 'dock', name: 'Dock Scheduler', status: 'published', organization: { name: 'Acme', is_verified: true }, price: { model: 'one_time', amount: 4900, currency: 'USD' } } }),
        ],
        meta: { total: 2 },
    };

    it('lists releases oldest first with age, tags and the claimer', () => {
        render(<Queue tab="waiting" releases={releases} claimers={{ rel_old: { id: 'acc_me', name: 'Me' } }} counts={{ waiting: 2, publish: 1 }} />);
        const rows = document.querySelectorAll('[data-queue-row]');
        expect([...rows].map((row) => row.dataset.release)).toEqual(['rel_old', 'rel_new']);
        expect(rows[0]).toHaveAttribute('href', '/review/releases/rel_old');
        expect(rows[0].querySelector('[data-age]').dataset.age).toBe('over');
        expect(within(rows[0]).getByText('over target')).toBeInTheDocument();
        expect(within(rows[0]).getByText('Paid · $49')).toBeInTheDocument();
        expect(within(rows[0]).getByText('In review · you')).toBeInTheDocument();
        expect(within(rows[1]).getByText('First release')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Waiting · 2' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: 'To publish · 1' })).toHaveAttribute('href', '/review/publish');
    });

    it('filters with chips and moves focus with J and K inside the list', () => {
        render(<Queue tab="waiting" releases={releases} counts={{}} />);
        fireEvent.click(screen.getByRole('button', { name: 'Paid' }));
        expect([...document.querySelectorAll('[data-queue-row]')].map((row) => row.dataset.release)).toEqual(['rel_old']);
        fireEvent.click(screen.getByRole('button', { name: 'Paid' }));

        const rows = document.querySelectorAll('[data-queue-row]');
        rows[0].focus();
        fireEvent.keyDown(rows[0], { key: 'j' });
        expect(document.activeElement).toBe(rows[1]);
        fireEvent.keyDown(rows[1], { key: 'k' });
        expect(document.activeElement).toBe(rows[0]);
    });

    it('shows an empty state per tab', () => {
        render(<Queue tab="failed" releases={{ data: [], meta: { total: 0 } }} counts={{}} />);
        expect(screen.getByText('No releases with failed checks')).toBeInTheDocument();
    });
});
