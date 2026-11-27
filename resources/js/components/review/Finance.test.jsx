import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { vi } from 'vitest';
import Finance from '../../pages/Review/Finance';
import { monthLabel, refundError, refundSplit, toMajor, toMinor } from './finance';

const { post, reload, get } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), get: vi.fn() }));

vi.mock('../../lib/api', () => ({
    api: { post },
    ApiError: class ApiError extends Error {},
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { account: { id: 'acc_me', is_staff: true } }, flash: null }, url: '/review/finance' }),
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
    router: { reload, get, visit: vi.fn() },
}));

vi.mock('../../layouts/SiteLayout', async () => {
    const { ToastProvider } = await import('../Toasts');
    return { default: ({ children }) => <ToastProvider>{children}</ToastProvider> };
});

const ZERO = { sales: 0, gross: 0, refunds: 0, refunded: 0, fee: 0, fee_refunded: 0, fee_revenue: 0, publisher_net: 0, disputes: 0, disputed: 0 };
const NORTHWIND = { id: 'org_nw', slug: 'northwind', name: 'Northwind Labs' };

const SUMMARY = {
    from: '2025-11-01',
    to: '2026-10-23',
    totals: [{ ...ZERO, currency: 'USD', sales: 4, gross: 25876, refunds: 2, refunded: 6292, fee: 2470, fee_refunded: 583, fee_revenue: 1887, publisher_net: 17697, disputes: 1, disputed: 10000 }],
    months: [
        { ...ZERO, month: '2026-09', currency: 'USD', sales: 1, gross: 5292, fee_revenue: 397 },
        { ...ZERO, month: '2026-10', currency: 'USD', sales: 3, gross: 20584, fee_revenue: 1490 },
    ],
    publishers: [{ ...ZERO, organization: NORTHWIND, currency: 'USD', sales: 3, gross: 15876, fee_revenue: 887 }],
};

function purchase(overrides = {}) {
    return {
        id: 'pur_1',
        extension: { id: 'ext_1', slug: 'route-optimizer', name: 'Route Optimizer' },
        publisher: NORTHWIND,
        customer: { id: 'org_gx', slug: 'globex', name: 'Globex' },
        gross: 5292,
        fee: 490,
        net: 4802,
        tax: 392,
        refunded: 0,
        fee_refunded: 0,
        refundable: 5292,
        currency: 'USD',
        status: 'paid',
        dispute: null,
        created_at: '2026-10-01T12:00:00Z',
        ...overrides,
    };
}

const PURCHASES = {
    data: [purchase(), purchase({ id: 'pur_refunded', status: 'refunded', refunded: 5292, refundable: 0 }), purchase({ id: 'pur_disputed', status: 'disputed', refundable: 0, dispute: { reason: 'fraudulent' } })],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 3 },
};

function renderFinance(props = {}) {
    return render(<Finance summary={SUMMARY} purchases={PURCHASES} filters={{}} stripe={{ configured: true }} counts={{ waiting: 1, publish: 0 }} {...props} />);
}

beforeEach(() => {
    post.mockReset();
    reload.mockReset();
    get.mockReset();
});

describe('finance helpers', () => {
    it('reads typed amounts as minor units', () => {
        expect(toMinor('42.92')).toBe(4292);
        expect(toMinor('$42')).toBe(4200);
        expect(toMinor('42,5')).toBe(4250);
        expect(toMinor('4.999')).toBeNull();
        expect(toMinor('abc')).toBeNull();
        expect(toMajor(4292)).toBe('42.92');
        expect(monthLabel('2026-10')).toBe('Oct 2026');
        expect(monthLabel('2026-09')).toBe('Sep 2026');
        expect(monthLabel('bogus')).toBe('bogus');
    });

    it('splits a refund between the fee and the publisher in proportion', () => {
        expect(refundSplit({ gross: 5292, fee: 490 }, 5292)).toEqual({ fee: 490, publisher: 4802 });
        expect(refundSplit({ gross: 5292, fee: 490 }, 1000)).toEqual({ fee: 93, publisher: 907 });
        expect(refundError('60', 5292, 'USD')).toBe('At most 52.92 USD can be refunded.');
        expect(refundError('0', 5292, 'USD')).toMatch(/Enter the amount/);
        expect(refundError('52.92', 5292, 'USD')).toBeNull();
    });
});

describe('Review/Finance', () => {
    it('shows totals, months, publishers and CSV links for the range', () => {
        renderFinance();
        const totals = document.querySelector('[data-totals="USD"]');
        expect(within(totals).getByText('$18.87')).toBeInTheDocument();
        expect(within(totals).getByText('$24.70 in fees, $5.83 returned on refunds')).toBeInTheDocument();
        expect(within(totals).getByText('$100')).toBeInTheDocument();
        expect(document.querySelector('[data-row="2026-09-USD"]')).toHaveTextContent('Sep 2026 USD');
        expect(within(document.querySelector('[data-row="org_nw-USD"]')).getByText('$8.87')).toBeInTheDocument();
        expect(document.querySelector('[data-csv="monthly"]')).toHaveAttribute('href', '/v1/admin/finance/monthly.csv?from=2025-11-01&to=2026-10-23');
        expect(document.querySelector('[data-csv="publisher"]')).toHaveAttribute('href', '/v1/admin/finance/monthly.csv?from=2025-11-01&to=2026-10-23&by=publisher');
        expect(screen.getByRole('link', { name: 'Finance' })).toHaveAttribute('aria-current', 'page');
    });

    it('offers Refund only where something is left to refund and Stripe is set up', () => {
        const { unmount } = renderFinance();
        expect(document.querySelector('[data-refund="pur_1"]')).toBeInTheDocument();
        expect(document.querySelector('[data-refund="pur_refunded"]')).toBeNull();
        expect(document.querySelector('[data-refund="pur_disputed"]')).toBeNull();
        expect(within(document.querySelector('[data-purchase="pur_disputed"]')).getByText('fraudulent')).toBeInTheDocument();
        unmount();

        renderFinance({ stripe: { configured: false } });
        expect(document.querySelector('[data-refund]')).toBeNull();
        expect(screen.getByText(/refunds are off/)).toBeInTheDocument();
    });

    it('filters purchases by status and publisher, keeping the range', () => {
        renderFinance({ filters: { from: '2026-10-01', to: '2026-10-31' } });
        fireEvent.change(document.querySelector('[data-filter="status"]'), { target: { value: 'paid' } });
        expect(get).toHaveBeenLastCalledWith('/review/finance', { from: '2026-10-01', to: '2026-10-31', status: 'paid' }, expect.objectContaining({ only: ['purchases', 'filters'] }));
        fireEvent.change(document.querySelector('[data-filter="publisher"]'), { target: { value: 'northwind' } });
        expect(get).toHaveBeenLastCalledWith('/review/finance', { from: '2026-10-01', to: '2026-10-31', publisher: 'northwind' }, expect.anything());
    });

    it('refunds a part, explaining who gives back what, and posts minor units', async () => {
        post.mockResolvedValue({ refund: { id: 're_1', amount: 1000, currency: 'USD', status: 'succeeded' } });
        renderFinance();
        fireEvent.click(document.querySelector('[data-refund="pur_1"]'));

        const amount = document.querySelector('[data-refund-amount]');
        expect(amount).toHaveValue('52.92');
        expect(document.querySelector('[data-refund-split]')).toHaveTextContent('Northwind Labs gives back $48.02 and Fleetbase returns $4.90 of its fee.');
        expect(document.querySelector('[data-refund-split]')).toHaveTextContent('the licence is revoked');

        fireEvent.change(amount, { target: { value: '60' } });
        fireEvent.click(document.querySelector('[data-confirm-refund]'));
        expect(screen.getByText('At most 52.92 USD can be refunded.')).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();

        fireEvent.change(amount, { target: { value: '10' } });
        expect(document.querySelector('[data-refund-split]')).toHaveTextContent('gives back $9.07 and Fleetbase returns $0.93');
        expect(document.querySelector('[data-refund-split]')).toHaveTextContent('A partial refund leaves the licence active.');
        fireEvent.change(document.querySelector('[data-refund-reason]'), { target: { value: 'duplicate' } });
        fireEvent.change(document.querySelector('[data-refund-note]'), { target: { value: ' Bought twice ' } });
        fireEvent.click(document.querySelector('[data-confirm-refund]'));

        await waitFor(() => expect(post).toHaveBeenCalledWith('/admin/purchases/pur_1/refund', { amount: 1000, reason: 'duplicate', note: 'Bought twice' }));
        expect(await screen.findByText('Refund of $10 sent to Stripe. The purchase updates when Stripe confirms it.')).toBeInTheDocument();
        expect(reload).toHaveBeenCalled();
    });
});
