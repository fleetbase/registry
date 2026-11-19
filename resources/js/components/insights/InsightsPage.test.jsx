import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import Insights from '../../pages/Studio/Insights';
import TrendChart from './TrendChart';

let page;

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { get: vi.fn() },
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
}));

vi.mock('../../layouts/SiteLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

const acme = { id: 'org_acme', slug: 'acme', name: 'Acme Fleet Tools', role: 'owner', is_personal: false };
const extensions = [
    { id: 'ext_1', slug: 'route-optimizer', name: 'Route Optimizer', status: 'published' },
    { id: 'ext_2', slug: 'pallet-wms', name: 'Pallet WMS', status: 'draft' },
];

function series(days) {
    return days.map(([date, installs, uninstalls, updates = 0]) => ({ date, installs, updates, uninstalls, purchases: 0, revenue: [] }));
}

const listingStats = {
    extension: { id: 'ext_1', slug: 'route-optimizer', name: 'Route Optimizer', status: 'published' },
    range: { from: '2026-10-01', to: '2026-10-03', days: 3, group: 'day' },
    previous: { from: '2026-09-28', to: '2026-09-30', days: 3 },
    as_of: '2026-10-03T09:00:00+00:00',
    totals: {
        installs: 41,
        updates: 5,
        uninstalls: 3,
        purchases: 9,
        revenue: [
            { currency: 'USD', purchases: 8, gross: 134100, refunded: 0, fee: 13410, net: 120690 },
            { currency: 'EUR', purchases: 1, gross: 4000, refunded: 0, fee: 400, net: 3600 },
        ],
        active_instances: 214,
    },
    previous_totals: { installs: 36, updates: 0, uninstalls: 1, purchases: 7, revenue: [{ currency: 'USD', purchases: 7, gross: 100000, refunded: 0, fee: 10000, net: 90000 }], active_instances: 180 },
    series: series([
        ['2026-10-01', 10, 1],
        ['2026-10-02', 25, 0, 3],
        ['2026-10-03', 6, 2],
    ]),
    versions: [
        { release: 'rel_2', version: '0.9.1', instances: 178, share: 0.8318 },
        { release: 'rel_1', version: '0.9.0', instances: 29, share: 0.1355 },
        { release: 'rel_0', version: '0.8.4', instances: 7, share: 0.0327 },
    ],
    events: {
        total: 58,
        data: [
            { type: 'install', at: '2026-10-03T08:41:00Z', extension: null, organization: { name: 'Northwind Logistics' }, version: '0.9.1', from_version: null, amount: null, currency: null },
            { type: 'purchase', at: '2026-10-02T17:02:00Z', extension: null, organization: { name: 'Northwind Logistics' }, version: null, from_version: null, amount: 14900, currency: 'USD' },
            { type: 'update', at: '2026-10-01T09:30:00Z', extension: null, organization: { name: 'Atlas Cold Chain' }, version: '0.9.1', from_version: '0.9.0', amount: null, currency: null },
            { type: 'refund', at: '2026-10-01T08:00:00Z', extension: null, organization: { name: 'Harbor Freight' }, version: null, from_version: null, amount: 4900, currency: 'USD', partial: true },
        ],
    },
};

const organizationStats = {
    ...listingStats,
    extension: undefined,
    versions: undefined,
    organization: { id: 'org_acme', slug: 'acme', name: 'Acme Fleet Tools' },
    extensions: [
        { extension: { id: 'ext_1', slug: 'route-optimizer', name: 'Route Optimizer', status: 'published' }, installs: 30, updates: 5, uninstalls: 2, purchases: 9, revenue: [{ currency: 'USD', net: 120690 }], active_instances: 200 },
        { extension: { id: 'ext_9', slug: 'retired', name: 'Retired', status: 'published' }, installs: 0, updates: 0, uninstalls: 1, purchases: 0, revenue: [], active_instances: 0 },
    ],
};

beforeEach(() => {
    vi.clearAllMocks();
    page = { url: '/studio/insights', props: { auth: { account: null } } };
});

describe('Insights', () => {
    it('shows tiles against the previous period, the trend, versions and recent events for one listing', () => {
        render(<Insights organizations={[acme]} organization={acme} extensions={extensions} extension="route-optimizer" stats={listingStats} filters={{}} />);

        const installs = screen.getByText('Installs', { selector: '[data-stat] > span' }).closest('[data-stat]');
        expect(within(installs).getByText('41')).toBeInTheDocument();
        expect(within(installs).getByText('▲ 14%')).toHaveAttribute('data-delta', 'good');
        expect(within(installs).getByText('5 updates besides')).toBeInTheDocument();

        const uninstalls = document.querySelector('[data-stat="uninstalls"]');
        expect(within(uninstalls).getByText('▲ +2')).toHaveAttribute('data-delta', 'bad');
        expect(within(document.querySelector('[data-stat="active_instances"]')).getByText('since 1 Oct')).toBeInTheDocument();

        const net = document.querySelector('[data-stat="net"]');
        expect(within(net).getByText('Net revenue (USD)')).toBeInTheDocument();
        expect(within(net).getByText('$1,206.90')).toBeInTheDocument();
        expect(within(net).getByText('Also €36')).toBeInTheDocument();

        expect(screen.getByRole('heading', { name: 'Installs and uninstalls per day' })).toBeInTheDocument();
        const versions = document.querySelector('[data-versions]');
        expect(within(versions).getByText('v0.9.1')).toBeInTheDocument();
        expect(within(versions).getByText('214 instances')).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'By extension' })).not.toBeInTheDocument();

        const events = document.querySelector('[data-events]');
        expect(within(events).getByText('Latest 4 of 58 in this range')).toBeInTheDocument();
        expect(within(events).getByText('v0.9.0 → v0.9.1')).toBeInTheDocument();
        expect(within(events).getByText('Partly refunded')).toBeInTheDocument();
        expect(within(events).getByText('−$49')).toBeInTheDocument();
        expect(within(events).queryByRole('columnheader', { name: 'Extension' })).not.toBeInTheDocument();

        expect(screen.getByRole('link', { name: /Export CSV/ })).toHaveAttribute('href', '/v1/publisher/extensions/route-optimizer/stats.csv?from=2026-10-01&to=2026-10-03&group=day');
        expect(document.querySelector('[data-as-of]')).toHaveTextContent('days in UTC · compared with 28 Sep – 30 Sep 2026');
    });

    it('summarises every listing with a row each, and picks one from its row', async () => {
        const user = userEvent.setup();
        render(<Insights organizations={[acme]} organization={acme} extensions={extensions} extension={null} stats={organizationStats} filters={{}} />);

        expect(screen.getByText('All extensions of Acme Fleet Tools, 1 Oct – 3 Oct 2026')).toBeInTheDocument();
        const table = document.querySelector('[data-by-extension]');
        expect(within(table).getByText('(deleted)')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Export CSV/ })).toHaveAttribute('href', '/v1/organizations/acme/stats.csv?from=2026-10-01&to=2026-10-03&group=day');
        expect(within(document.querySelector('[data-events]')).getByRole('columnheader', { name: 'Extension' })).toBeInTheDocument();

        await user.click(within(table).getByRole('button', { name: 'Route Optimizer' }));
        expect(router.get).toHaveBeenCalledWith('/studio/insights/acme', { extension: 'route-optimizer', from: '2026-10-01', to: '2026-10-03' }, expect.objectContaining({ only: ['stats', 'filters', 'extension', 'error'] }));
    });

    it('changes the listing, the range and the grouping from the filter row', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-23T12:00:00Z'));
        const user = userEvent.setup();
        render(<Insights organizations={[acme]} organization={acme} extensions={extensions} extension={null} stats={organizationStats} filters={{}} />);

        await user.selectOptions(screen.getByLabelText('Extension'), 'pallet-wms');
        expect(router.get).toHaveBeenLastCalledWith('/studio/insights/acme', { extension: 'pallet-wms', from: '2026-10-01', to: '2026-10-03' }, expect.anything());

        await user.click(screen.getByRole('button', { name: '90 days' }));
        expect(router.get).toHaveBeenLastCalledWith('/studio/insights/acme', { from: '2026-07-26', to: '2026-10-23' }, expect.anything());

        await user.selectOptions(screen.getByLabelText('Group by'), 'week');
        expect(router.get).toHaveBeenLastCalledWith('/studio/insights/acme', { from: '2026-10-01', to: '2026-10-03', group: 'week' }, expect.anything());

        const custom = screen.getByRole('form', { name: 'Custom range' });
        fireEvent.change(within(custom).getByLabelText('From'), { target: { value: '2026-09-01' } });
        fireEvent.change(within(custom).getByLabelText('To'), { target: { value: '2026-09-30' } });
        await user.click(within(custom).getByRole('button', { name: 'Show' }));
        expect(router.get).toHaveBeenLastCalledWith('/studio/insights/acme', { from: '2026-09-01', to: '2026-09-30' }, expect.anything());
        vi.useRealTimers();
    });

    it('explains an empty organization and a refused range', () => {
        const { rerender } = render(<Insights organizations={[acme]} organization={acme} extensions={[]} stats={null} />);
        expect(screen.getByRole('heading', { name: 'No extensions yet' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /New extension/ })).toHaveAttribute('href', '/studio/new');

        rerender(<Insights organizations={[]} organization={null} />);
        expect(screen.getByRole('heading', { name: 'No organization to show' })).toBeInTheDocument();

        rerender(<Insights organizations={[acme]} organization={acme} extensions={extensions} stats={null} error="A report covers at most 731 days." />);
        expect(screen.getByRole('alert')).toHaveTextContent('A report covers at most 731 days.');
    });
});

describe('TrendChart', () => {
    const rows = listingStats.series;

    it('describes itself and lists every value in a table', () => {
        render(<TrendChart series={rows} group="day" />);

        expect(screen.getByRole('img')).toHaveAccessibleName(/41 installs and 3 uninstalls over 3 days/);
        const table = screen.getByRole('table');
        expect(within(table).getAllByRole('row')).toHaveLength(4);
        expect(within(table).getByRole('row', { name: '2 Oct 25 3 0' })).toBeInTheDocument();
    });

    it('reads out each bucket with the arrow keys', () => {
        render(<TrendChart series={rows} group="day" />);
        const slider = screen.getByRole('slider', { name: 'Installs and uninstalls by day' });

        fireEvent.focus(slider);
        expect(slider).toHaveAttribute('aria-valuetext', '3 Oct: 6 installs, 2 uninstalls');
        fireEvent.keyDown(slider, { key: 'ArrowLeft' });
        expect(slider).toHaveAttribute('aria-valuetext', '2 Oct: 25 installs, 0 uninstalls');
        expect(screen.getByRole('status')).toHaveTextContent('3 updated');
        fireEvent.keyDown(slider, { key: 'Home' });
        fireEvent.keyDown(slider, { key: 'ArrowLeft' });
        expect(slider).toHaveAttribute('aria-valuetext', '1 Oct: 10 installs, 1 uninstalls');
        fireEvent.blur(slider);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('draws a lone bucket as points and an empty range as an empty frame', () => {
        const { container, rerender } = render(<TrendChart series={rows.slice(0, 1)} group="month" />);
        expect(container.querySelectorAll('polyline')).toHaveLength(0);
        expect(container.querySelectorAll('circle.ins-dot')).toHaveLength(2);

        rerender(<TrendChart series={[]} group="day" />);
        expect(screen.queryByRole('slider')).not.toBeInTheDocument();
    });
});
