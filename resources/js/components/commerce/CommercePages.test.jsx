import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import Payouts, { scheduleLabel } from '../../pages/Studio/Payouts';
import Billing from '../../pages/Account/Billing';
import { BuyPanel } from '../../pages/Extension/Show';
import { api, ApiError } from '../../lib/api';

let page;

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { reload: vi.fn(), get: vi.fn() },
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
}));

vi.mock('../../layouts/SiteLayout', async () => {
    const { ToastProvider } = await import('../Toasts');
    return { default: ({ children }) => <ToastProvider>{children}</ToastProvider> };
});

vi.mock('../../lib/api', async () => {
    const actual = await vi.importActual('../../lib/api');
    return { ApiError: actual.ApiError, api: { get: vi.fn(), post: vi.fn() } };
});

// Connect.js loads from Stripe in the browser; the pages only need to know which component mounts.
vi.mock('./ConnectEmbed', () => ({ default: ({ component }) => <div data-testid={`connect-${component}`} /> }));

const acme = { id: 'org_acme', slug: 'acme', name: 'Acme Fleet Tools', role: 'owner', is_personal: false };

beforeEach(() => {
    vi.clearAllMocks();
    page = { url: '/', props: { auth: { account: null }, commerce: { fee_bps: 1000, enabled: true } } };
});

afterEach(() => vi.useRealTimers());

describe('Payouts', () => {
    const stripe = { configured: true, publishable_key: 'pk_test' };
    const transactions = {
        data: [
            { id: 'pur_1', extension: { name: 'Route Optimizer' }, customer: { name: 'Globex' }, gross: 5292, fee: 490, net: 4802, currency: 'USD', status: 'paid', created_at: '2026-10-20T12:00:00Z' },
            { id: 'pur_2', extension: { name: 'Route Optimizer' }, customer: { name: 'Initech' }, gross: 4900, fee: 490, net: 4410, currency: 'USD', status: 'refunded', created_at: '2026-10-19T12:00:00Z' },
        ],
        totals: [{ currency: 'USD', count: 2, gross: 10192, fee: 980, net: 9212 }],
        meta: { current_page: 1, last_page: 1, total: 2 },
    };

    it('describes payout schedules', () => {
        expect(scheduleLabel({ interval: 'weekly', weekly_anchor: 'friday', delay_days: 2 })).toBe('Weekly on Friday, 2 days after payment');
        expect(scheduleLabel({ interval: 'manual' })).toBe('Manual');
        expect(scheduleLabel(null)).toBeNull();
    });

    it('lets an owner set up payouts and reloads the account', async () => {
        const user = userEvent.setup();
        api.post.mockResolvedValue({ connected: true });
        render(<Payouts organizations={[acme]} organization={acme} payouts={{ connect: { connected: false } }} transactions={{ data: [], totals: [], meta: null }} stripe={stripe} />);

        expect(screen.getByText('Not set up')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Set up payouts' }));

        expect(api.post).toHaveBeenCalledWith('organizations/acme/stripe/account');
        expect(router.reload).toHaveBeenCalledWith({ only: ['payouts'] });
        expect(screen.getByText(/No sales yet/)).toBeInTheDocument();
    });

    it('shows onboarding until Stripe enables payouts, and only to owners and admins', () => {
        const connect = { connected: true, payout_status: 'restricted', requirements_due: ['external_account'] };
        const { rerender } = render(<Payouts organizations={[acme]} organization={acme} payouts={{ connect }} transactions={transactions} stripe={stripe} />);
        expect(screen.getByTestId('connect-account-onboarding')).toBeInTheDocument();
        expect(screen.getByText('Stripe still needs 1 item.')).toBeInTheDocument();

        const billing = { ...acme, role: 'billing' };
        rerender(<Payouts organizations={[billing]} organization={billing} payouts={{ connect }} transactions={transactions} stripe={stripe} />);
        expect(screen.queryByTestId('connect-account-onboarding')).not.toBeInTheDocument();
        expect(screen.getByText('An owner or admin of Acme Fleet Tools finishes this.')).toBeInTheDocument();
    });

    it('shows the balance, schedule and sales with fee, net and totals', async () => {
        const user = userEvent.setup();
        const payouts = {
            connect: { connected: true, payout_status: 'enabled', default_currency: 'USD' },
            balance: { available: [{ amount: 12880, currency: 'USD' }], pending: [{ amount: 4410, currency: 'USD' }], in_transit: [] },
            schedule: { interval: 'daily', delay_days: 2 },
        };
        render(<Payouts organizations={[acme]} organization={acme} payouts={payouts} transactions={transactions} filters={{ status: 'paid' }} stripe={stripe} />);

        expect(screen.getByText('Payouts on')).toBeInTheDocument();
        const balance = screen.getByRole('region', { name: 'Balance' });
        expect(within(balance).getByText('$128.80')).toBeInTheDocument();
        expect(within(balance).getByText('$44.10')).toBeInTheDocument();
        expect(within(balance).getByText('Daily, 2 days after payment')).toBeInTheDocument();

        const rows = screen.getAllByRole('row');
        expect(within(rows[1]).getByText('Globex')).toBeInTheDocument();
        expect(within(rows[1]).getByText('−$4.90')).toBeInTheDocument();
        expect(within(rows[1]).getByText('$48.02')).toBeInTheDocument();
        expect(within(rows[2]).getByText('Refunded')).toBeInTheDocument();
        expect(screen.getByText('Total, 2 sales not refunded (USD)')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'CSV' })).toHaveAttribute('href', '/v1/organizations/acme/payouts/transactions.csv?status=paid');

        await user.click(screen.getByRole('button', { name: 'Bank account, tax and payout schedule' }));
        expect(screen.getByTestId('connect-account-management')).toBeInTheDocument();
        expect(screen.getByTestId('connect-tax-registrations')).toBeInTheDocument();
    });

    it('says when payments are not set up on the registry', () => {
        render(<Payouts organizations={[acme]} organization={acme} payouts={null} transactions={null} stripe={{ configured: false }} />);
        expect(screen.getByText(/Payments are not set up on this registry yet/)).toBeInTheDocument();
    });
});

describe('Billing', () => {
    const purchase = {
        id: 'pur_1',
        extension: { slug: 'route-optimizer', name: 'Route Optimizer' },
        bought_by: { name: 'Hank Scorpio' },
        amount_total: 5292,
        amount_tax: 392,
        currency: 'USD',
        status: 'paid',
        receipt_url: 'https://pay.stripe.com/receipts/x',
        created_at: '2026-10-20T12:00:00Z',
    };
    const licence = {
        id: 'lic_1',
        extension: { name: 'Route Optimizer' },
        kind: 'paid',
        status: 'active',
        max_activations: 5,
        seats_used: 1,
        activations: [{ id: 'a1', instance: { name: 'Depot', host: 'fleet.globex.example' }, release: '1.2.0', activated_at: '2026-10-21T08:00:00Z' }],
    };

    it('lists purchases with receipts and licences with their instances', () => {
        render(<Billing organizations={[acme]} organization={acme} purchases={[purchase]} licences={[licence]} />);

        expect(screen.getByRole('link', { name: /Receipt/ })).toHaveAttribute('href', 'https://pay.stripe.com/receipts/x');
        expect(screen.getByText('Hank Scorpio')).toBeInTheDocument();
        expect(screen.getByText(/incl\. \$3\.92 tax/)).toBeInTheDocument();
        expect(screen.getByText('1 of 5 instances')).toBeInTheDocument();
        expect(screen.getByText(/Depot/)).toBeInTheDocument();
        expect(screen.getByText(/v1\.2\.0/)).toBeInTheDocument();
    });

    it('shows members their licences but not the purchases', () => {
        const member = { ...acme, role: 'member' };
        render(<Billing organizations={[member]} organization={member} purchases={null} licences={[licence]} />);

        expect(screen.getByText('Purchases and receipts are for Acme Fleet Tools’s owners, admins and billing members.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Receipt/ })).not.toBeInTheDocument();
        expect(screen.getByText('1 of 5 instances')).toBeInTheDocument();
    });

    it('shows Finalizing and polls the hand-off until the purchase lands', async () => {
        vi.useFakeTimers();
        const checkout = { handoff: 'chk_1', status: 'open', extension: { slug: 'route-optimizer', name: 'Route Optimizer' } };
        api.get.mockResolvedValueOnce({ ...checkout }).mockResolvedValueOnce({ ...checkout, status: 'complete' });
        render(<Billing organizations={[acme]} organization={acme} purchases={[]} licences={[]} checkout={checkout} />);

        expect(screen.getByText('Finalizing your purchase of Route Optimizer…')).toBeInTheDocument();
        await act(async () => vi.advanceTimersByTimeAsync(2000));
        expect(api.get).toHaveBeenCalledWith('purchases/chk_1');
        expect(router.reload).not.toHaveBeenCalled();

        await act(async () => vi.advanceTimersByTimeAsync(2000));
        expect(screen.getByText(/Acme Fleet Tools now holds a licence for Route Optimizer/)).toBeInTheDocument();
        expect(router.reload).toHaveBeenCalledWith({ only: ['purchases', 'licences'] });
    });
});

describe('Buy on the website', () => {
    const extension = { slug: 'route-optimizer', publisher: { id: 'org_pub' }, price: { model: 'one_time', amount: 4900, effective_amount: 2900, currency: 'USD', on_sale: true } };

    it('asks visitors to sign in', () => {
        render(<BuyPanel extension={extension} account={null} />);
        expect(screen.getByRole('link', { name: 'Sign in to buy' })).toHaveAttribute('href', '/sign-in');
    });

    it('buys for one of the organizations the account may buy for and goes to Stripe', async () => {
        const user = userEvent.setup();
        const assign = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign });
        api.post.mockResolvedValue({ url: 'https://checkout.stripe.com/c/pay/cs_test_1' });
        const account = {
            organizations: [
                { id: 'org_me', slug: 'me', name: 'Me', role: 'owner', is_personal: true },
                { id: 'org_globex', slug: 'globex', name: 'Globex', role: 'billing', is_personal: false },
                { id: 'org_other', slug: 'other', name: 'Other', role: 'publisher', is_personal: false },
            ],
        };
        render(<BuyPanel extension={extension} account={account} />);

        const select = screen.getByLabelText('Buy for');
        expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual(['Me', 'Globex']);
        expect(select).toHaveValue('globex');
        await user.click(screen.getByRole('button', { name: 'Buy for $29' }));

        expect(api.post).toHaveBeenCalledWith('purchases/checkout', { extension: 'route-optimizer', organization: 'globex' });
        expect(assign).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_test_1');
        vi.unstubAllGlobals();
    });

    it('shows why a checkout was refused', async () => {
        const user = userEvent.setup();
        api.post.mockRejectedValue(new ApiError(409, { errors: ['Globex already holds a licence for Route Optimizer. Ask Fleetbase staff for more seats.'] }));
        render(<BuyPanel extension={extension} account={{ organizations: [{ id: 'org_globex', slug: 'globex', name: 'Globex', role: 'admin' }] }} />);

        await user.click(screen.getByRole('button', { name: 'Buy for $29' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Globex already holds a licence');
    });
});
