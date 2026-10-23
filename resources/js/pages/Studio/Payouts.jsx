import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ConnectEmbed from '../../components/commerce/ConnectEmbed';
import Icon from '../../components/Icon';
import { StatusChip } from '../../components/Status';
import { StudioNav } from '../../components/studio/StudioChrome';
import { useToast } from '../../components/Toasts';
import { EmptyState, Note } from '../../components/Ui';
import { api } from '../../lib/api';
import { money, shortDate } from '../../lib/format';
import { payoutStatus, purchaseStatus } from '../../lib/statuses';

const MANAGING = ['owner', 'admin'];

/** `weekly on Friday, 2 days after payment` from a Stripe payout schedule. */
export function scheduleLabel(schedule) {
    if (!schedule?.interval) {
        return null;
    }
    const when = {
        manual: 'Manual',
        daily: 'Daily',
        weekly: `Weekly${schedule.weekly_anchor ? ` on ${schedule.weekly_anchor[0].toUpperCase()}${schedule.weekly_anchor.slice(1)}` : ''}`,
        monthly: `Monthly${schedule.monthly_anchor ? ` on day ${schedule.monthly_anchor}` : ''}`,
    }[schedule.interval] ?? schedule.interval;
    return schedule.delay_days != null && schedule.interval !== 'manual' ? `${when}, ${schedule.delay_days} days after payment` : when;
}

/** Amounts per currency as one line: `$128.80 · €40`. */
function amounts(list = []) {
    return list.length ? list.map((item) => money(item.amount, item.currency)).join(' · ') : money(0);
}

/**
 * Payouts for a publisher organization: the Stripe Connect account (embedded onboarding and
 * management), balance and schedule, and every sale with Fleetbase's fee and the net, with a CSV
 * export. Owners, admins and billing members.
 */
export default function Payouts({ organizations = [], organization, payouts, transactions, filters = {}, error, stripe }) {
    return (
        <SiteLayout title="Payouts" section="studio">
            <div className="wrap" style={{ paddingTop: 36, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <div className="row between wrapflex" style={{ gap: 16 }}>
                        <div className="col" style={{ gap: 6 }}>
                            <h1 className="h1">Payouts</h1>
                            {organization && <p className="ink2">{organization.name}: what your extensions earned and when Stripe pays it out.</p>}
                        </div>
                        <StudioNav current="payouts" />
                    </div>
                    {organizations.length > 1 && (
                        <nav className="seg" aria-label="Organization" style={{ alignSelf: 'flex-start', maxWidth: '100%', overflowX: 'auto' }}>
                            {organizations.map((item) => (
                                <Link key={item.id} href={`/studio/payouts/${item.slug}`} aria-current={item.slug === organization?.slug ? 'page' : undefined}>
                                    {item.name}
                                </Link>
                            ))}
                        </nav>
                    )}
                    {!organization ? (
                        <EmptyState icon="wallet" title="No organization to show">
                            Payouts are for organization owners, admins and billing members. Ask an owner to give you the billing role.
                        </EmptyState>
                    ) : !stripe?.configured ? (
                        <Note tone="info">Payments are not set up on this registry yet, so extensions here are free.</Note>
                    ) : (
                        <PayoutsBody organization={organization} payouts={payouts} transactions={transactions} filters={filters} error={error} publishableKey={stripe.publishable_key} />
                    )}
                </div>
            </div>
        </SiteLayout>
    );
}

function PayoutsBody({ organization, payouts, transactions, filters, error, publishableKey }) {
    const connect = payouts?.connect ?? { connected: false };
    const manages = MANAGING.includes(organization.role);

    return (
        <>
            <ConnectCard organization={organization} connect={connect} manages={manages} publishableKey={publishableKey} />
            {error && (
                <Note tone="you" role="alert">
                    <span className="small">{error}</span>
                </Note>
            )}
            {payouts?.balance && (
                <section className="grid3" aria-label="Balance" data-balance>
                    <Stat label="Available" value={amounts(payouts.balance.available)} hint="Paid out on the next payout" />
                    <Stat label="Pending" value={amounts(payouts.balance.pending)} hint="Not yet available from Stripe" />
                    <Stat label="In transit" value={amounts(payouts.balance.in_transit)} hint={scheduleLabel(payouts.schedule) ?? 'On its way to your bank'} />
                </section>
            )}
            <Sales organization={organization} transactions={transactions} filters={filters} />
        </>
    );
}

function Stat({ label, value, hint }) {
    return (
        <div className="card card-pad col" style={{ gap: 4 }}>
            <span className="small muted">{label}</span>
            <span className="h2 num">{value}</span>
            {hint && <span className="xs muted">{hint}</span>}
        </div>
    );
}

/** The Connect account: set it up, finish onboarding, or manage it, through Stripe's components. */
function ConnectCard({ organization, connect, manages, publishableKey }) {
    const toast = useToast();
    const [busy, setBusy] = useState(false);
    const [managing, setManaging] = useState(false);
    const status = payoutStatus(connect.connected ? connect.payout_status : null);

    async function create() {
        setBusy(true);
        try {
            await api.post(`organizations/${organization.slug}/stripe/account`);
            router.reload({ only: ['payouts'] });
        } catch (e) {
            toast(e.message, { type: 'error' });
        } finally {
            setBusy(false);
        }
    }

    async function onboardingExited() {
        try {
            await api.get(`organizations/${organization.slug}/stripe`, { refresh: 1 });
        } finally {
            router.reload({ only: ['payouts'] });
        }
    }

    return (
        <section className="card" aria-labelledby="h-connect" data-connect-status={connect.payout_status ?? 'none'}>
            <div className="card-head row between wrapflex">
                <h2 id="h-connect" className="h3">
                    Stripe account
                </h2>
                <StatusChip tone={status.tone} icon={status.icon}>
                    {status.label}
                </StatusChip>
            </div>
            <div className="card-body stack">
                {!connect.connected ? (
                    <>
                        <p className="ink2">Sales go to a Stripe account in {organization.name}’s name. Fleetbase keeps its facilitator fee and Stripe pays the rest to your bank.</p>
                        {manages ? (
                            <div className="row">
                                <button type="button" className="btn btn-primary" onClick={create} disabled={busy} aria-busy={busy || undefined} data-connect-create>
                                    <Icon name="wallet" />
                                    {busy ? 'Setting up…' : 'Set up payouts'}
                                </button>
                            </div>
                        ) : (
                            <p className="small muted">An owner or admin of {organization.name} sets this up.</p>
                        )}
                    </>
                ) : connect.payout_status !== 'enabled' ? (
                    <>
                        <p className="ink2">{status.hint}</p>
                        {connect.requirements_due?.length > 0 && <p className="small muted">Stripe still needs {connect.requirements_due.length} item{connect.requirements_due.length === 1 ? '' : 's'}.</p>}
                        {manages ? (
                            <ConnectEmbed organization={organization.slug} publishableKey={publishableKey} component="account-onboarding" onExit={onboardingExited} label="Stripe onboarding" />
                        ) : (
                            <p className="small muted">An owner or admin of {organization.name} finishes this.</p>
                        )}
                    </>
                ) : (
                    <>
                        {manages && <ConnectEmbed organization={organization.slug} publishableKey={publishableKey} component="notification-banner" label="Stripe notices" />}
                        <p className="small ink2">
                            Charges and payouts are on{connect.default_currency ? `, in ${connect.default_currency} by default` : ''}.
                        </p>
                        {manages && (
                            <div className="col" style={{ gap: 12 }}>
                                <button type="button" className="btn" style={{ alignSelf: 'flex-start' }} onClick={() => setManaging((open) => !open)} aria-expanded={managing}>
                                    <Icon name={managing ? 'chevronDown' : 'arrowRight'} />
                                    Bank account, tax and payout schedule
                                </button>
                                {managing && (
                                    <>
                                        <ConnectEmbed organization={organization.slug} publishableKey={publishableKey} component="account-management" label="Stripe account management" />
                                        <ConnectEmbed organization={organization.slug} publishableKey={publishableKey} component="payouts" label="Stripe payouts" />
                                        <ConnectEmbed organization={organization.slug} publishableKey={publishableKey} component="tax-settings" label="Stripe tax settings" />
                                        <ConnectEmbed organization={organization.slug} publishableKey={publishableKey} component="tax-registrations" label="Stripe tax registrations" />
                                    </>
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>
        </section>
    );
}

/** Sales of the organization's extensions: gross, Fleetbase's fee, net, with filters and CSV. */
function Sales({ organization, transactions, filters }) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const rows = transactions?.data ?? [];
    const meta = transactions?.meta;
    const query = new URLSearchParams(Object.entries({ from, to, status }).filter(([, value]) => value)).toString();

    function apply(event) {
        event.preventDefault();
        router.get(`/studio/payouts/${organization.slug}`, Object.fromEntries(new URLSearchParams(query)), { preserveScroll: true, only: ['transactions', 'filters'] });
    }

    function page(number) {
        router.get(`/studio/payouts/${organization.slug}`, { ...Object.fromEntries(new URLSearchParams(query)), page: number }, { preserveScroll: true, only: ['transactions'] });
    }

    return (
        <section className="card" aria-labelledby="h-sales" data-sales>
            <div className="card-head row between wrapflex" style={{ gap: 12 }}>
                <h2 id="h-sales" className="h3">
                    Sales
                </h2>
                <a className="btn btn-sm" href={`/v1/organizations/${organization.slug}/payouts/transactions.csv${query ? `?${query}` : ''}`} download data-csv>
                    <Icon name="download" />
                    CSV
                </a>
            </div>
            <form className="card-body row wrapflex" style={{ gap: 12, alignItems: 'flex-end' }} onSubmit={apply}>
                <label className="field">
                    <span className="label">From</span>
                    <input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} />
                </label>
                <label className="field">
                    <span className="label">To</span>
                    <input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} />
                </label>
                <label className="field">
                    <span className="label">Status</span>
                    <select className="select" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">Any</option>
                        <option value="paid">Paid</option>
                        <option value="refunded">Refunded</option>
                        <option value="disputed">Disputed</option>
                    </select>
                </label>
                <button type="submit" className="btn">
                    Filter
                </button>
            </form>
            {rows.length === 0 ? (
                <div className="card-body">
                    <p className="small muted">No sales{query ? ' match these filters' : ' yet'}. Sales show here as soon as Stripe confirms the payment.</p>
                </div>
            ) : (
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Extension</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Status</th>
                                <th scope="col" className="num" style={{ textAlign: 'right' }}>
                                    Gross
                                </th>
                                <th scope="col" className="num" style={{ textAlign: 'right' }}>
                                    Fee
                                </th>
                                <th scope="col" className="num" style={{ textAlign: 'right' }}>
                                    Net
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => {
                                const s = purchaseStatus(row.status);
                                return (
                                    <tr key={row.id} data-sale={row.id}>
                                        <td className="nowrap">{shortDate(row.created_at)}</td>
                                        <td>{row.extension?.name ?? '—'}</td>
                                        <td>{row.customer?.name ?? '—'}</td>
                                        <td>
                                            <StatusChip tone={s.tone} icon={s.icon}>
                                                {s.label}
                                            </StatusChip>
                                        </td>
                                        <td className="num" style={{ textAlign: 'right' }}>
                                            {money(row.gross, row.currency)}
                                        </td>
                                        <td className="num muted" style={{ textAlign: 'right' }}>
                                            −{money(row.fee, row.currency)}
                                        </td>
                                        <td className="num" style={{ textAlign: 'right' }}>
                                            <strong>{money(row.net, row.currency)}</strong>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            {(transactions?.totals ?? []).map((total) => (
                                <tr key={total.currency}>
                                    <th scope="row" colSpan={4}>
                                        Total, {total.count} sale{total.count === 1 ? '' : 's'} ({total.currency})
                                    </th>
                                    <td className="num" style={{ textAlign: 'right' }}>
                                        {money(total.gross, total.currency)}
                                    </td>
                                    <td className="num muted" style={{ textAlign: 'right' }}>
                                        −{money(total.fee, total.currency)}
                                    </td>
                                    <td className="num" style={{ textAlign: 'right' }}>
                                        <strong>{money(total.net, total.currency)}</strong>
                                    </td>
                                </tr>
                            ))}
                        </tfoot>
                    </table>
                </div>
            )}
            {meta && meta.last_page > 1 && (
                <div className="card-body row between">
                    <button type="button" className="btn btn-sm" disabled={meta.current_page <= 1} onClick={() => page(meta.current_page - 1)}>
                        Newer
                    </button>
                    <span className="small muted">
                        Page {meta.current_page} of {meta.last_page}
                    </span>
                    <button type="button" className="btn btn-sm" disabled={meta.current_page >= meta.last_page} onClick={() => page(meta.current_page + 1)}>
                        Older
                    </button>
                </div>
            )}
        </section>
    );
}
