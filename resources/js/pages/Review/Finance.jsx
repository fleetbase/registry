import { useState } from 'react';
import { router } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Dialog from '../../components/Dialog';
import Icon from '../../components/Icon';
import { StatusChip } from '../../components/Status';
import { EmptyState, Field, Note } from '../../components/Ui';
import { ReviewTabs, useAction } from '../../components/review/Parts';
import { monthLabel, refundError, refundSplit, toMajor, toMinor } from '../../components/review/finance';
import { api } from '../../lib/api';
import { money, plural, shortDate } from '../../lib/format';
import { purchaseStatus } from '../../lib/statuses';

const RIGHT = { textAlign: 'right' };
const REASONS = [
    ['requested_by_customer', 'Requested by the customer'],
    ['duplicate', 'Duplicate purchase'],
    ['fraudulent', 'Fraudulent'],
];

/** Drop empty values from a query. */
function query(values) {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

/**
 * Finance: Fleetbase's fee revenue, refunds and disputes over a date range, per month and per
 * publisher, with a monthly CSV, and every purchase in the range with a refund action.
 */
export default function Finance({ summary = {}, purchases, filters = {}, stripe = {}, counts = {} }) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [refunding, setRefunding] = useState(null);
    const range = query({ from, to });
    const csv = new URLSearchParams(query({ from: summary.from, to: summary.to })).toString();

    function apply(event) {
        event.preventDefault();
        router.get('/review/finance', query({ ...range, status: filters.status, publisher: filters.publisher }), { preserveScroll: true });
    }

    return (
        <SiteLayout title="Finance" section="review">
            <div className="wrap" style={{ paddingTop: 36, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <div className="row between wrapflex" style={{ gap: 16 }}>
                        <div className="col" style={{ gap: 6 }}>
                            <h1 className="h1">Finance</h1>
                            <p className="ink2">
                                Fleetbase’s fee revenue, refunds and disputes from {shortDate(summary.from)} to {shortDate(summary.to)}, by the date of each sale.
                            </p>
                        </div>
                        <ReviewTabs current="finance" counts={counts} />
                    </div>

                    <form className="card card-pad row wrapflex" style={{ gap: 12, alignItems: 'flex-end' }} onSubmit={apply} aria-label="Date range">
                        <label className="field">
                            <span className="label">From</span>
                            <input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </label>
                        <label className="field">
                            <span className="label">To</span>
                            <input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} />
                        </label>
                        <button type="submit" className="btn" style={{ minHeight: 44 }}>
                            Show
                        </button>
                        <div className="row wrapflex grow" style={{ gap: 8, justifyContent: 'flex-end' }}>
                            <a className="btn btn-sm" href={`/v1/admin/finance/monthly.csv?${csv}`} download data-csv="monthly">
                                <Icon name="download" />
                                Monthly CSV
                            </a>
                            <a className="btn btn-sm" href={`/v1/admin/finance/monthly.csv?${csv}&by=publisher`} download data-csv="publisher">
                                <Icon name="download" />
                                By publisher
                            </a>
                        </div>
                    </form>

                    <Totals totals={summary.totals ?? []} />
                    <Breakdown
                        id="h-months"
                        title="By month"
                        rows={summary.months ?? []}
                        label={(row) => monthLabel(row.month)}
                        heading="Month"
                        empty="No sales in this range."
                        rowKey={(row) => `${row.month}-${row.currency}`}
                    />
                    <Breakdown
                        id="h-publishers"
                        title="By publisher"
                        rows={summary.publishers ?? []}
                        label={(row) => row.organization?.name ?? 'Unknown publisher'}
                        heading="Publisher"
                        empty="No publisher sold anything in this range."
                        rowKey={(row) => `${row.organization?.id ?? 'none'}-${row.currency}`}
                    />
                    <Purchases purchases={purchases} filters={filters} range={range} publishers={summary.publishers ?? []} stripe={stripe} onRefund={setRefunding} />
                </div>
            </div>
            <RefundDialog open={Boolean(refunding)} purchase={refunding} onClose={() => setRefunding(null)} />
        </SiteLayout>
    );
}

function Stat({ label, value, hint, ...rest }) {
    return (
        <div className="card card-pad col" style={{ gap: 4 }} {...rest}>
            <span className="small muted">{label}</span>
            <span className="h2 num">{value}</span>
            {hint && <span className="xs muted">{hint}</span>}
        </div>
    );
}

/** Per currency: fee revenue, gross sales, refunds and disputes. */
function Totals({ totals }) {
    if (!totals.length) {
        return (
            <EmptyState icon="ledger" title="No sales in this range">
                Purchases appear here as soon as Stripe confirms the payment.
            </EmptyState>
        );
    }
    return totals.map((total) => (
        <section key={total.currency} className="grid4" aria-label={`Totals in ${total.currency}`} data-totals={total.currency}>
            <Stat label={`Fee revenue (${total.currency})`} value={money(total.fee_revenue, total.currency)} hint={total.fee_refunded ? `${money(total.fee, total.currency)} in fees, ${money(total.fee_refunded, total.currency)} returned on refunds` : 'Fleetbase’s facilitator fees'} data-stat="fee_revenue" />
            <Stat label="Gross sales" value={money(total.gross, total.currency)} hint={`${plural(total.sales, 'sale')}, ${money(total.publisher_net, total.currency)} to publishers`} data-stat="gross" />
            <Stat label="Refunded" value={money(total.refunded, total.currency)} hint={plural(total.refunds, 'refund')} data-stat="refunded" />
            <Stat label="Disputed" value={money(total.disputed, total.currency)} hint={total.disputes ? `${plural(total.disputes, 'dispute')}, decided in Stripe` : 'No disputes'} data-stat="disputed" />
        </section>
    ));
}

/** A table of report rows (months or publishers). */
function Breakdown({ id, title, rows, label, heading, empty, rowKey }) {
    return (
        <section className="card" aria-labelledby={id}>
            <div className="card-head">
                <h2 id={id} className="h3">
                    {title}
                </h2>
            </div>
            {rows.length === 0 ? (
                <div className="card-body">
                    <p className="small muted">{empty}</p>
                </div>
            ) : (
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th scope="col">{heading}</th>
                                <th scope="col" className="num" style={RIGHT}>
                                    Sales
                                </th>
                                <th scope="col" className="num" style={RIGHT}>
                                    Gross
                                </th>
                                <th scope="col" className="num" style={RIGHT}>
                                    Refunded
                                </th>
                                <th scope="col" className="num" style={RIGHT}>
                                    Fee revenue
                                </th>
                                <th scope="col" className="num" style={RIGHT}>
                                    To publishers
                                </th>
                                <th scope="col" className="num" style={RIGHT}>
                                    Disputed
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={rowKey(row)} data-row={rowKey(row)}>
                                    <th scope="row" style={{ fontWeight: 500 }}>
                                        {label(row)} <span className="xs muted">{row.currency}</span>
                                    </th>
                                    <td className="num" style={RIGHT}>
                                        {row.sales}
                                    </td>
                                    <td className="num" style={RIGHT}>
                                        {money(row.gross, row.currency)}
                                    </td>
                                    <td className="num muted" style={RIGHT}>
                                        {row.refunded ? `−${money(row.refunded, row.currency)}` : '—'}
                                    </td>
                                    <td className="num" style={RIGHT}>
                                        <strong>{money(row.fee_revenue, row.currency)}</strong>
                                    </td>
                                    <td className="num" style={RIGHT}>
                                        {money(row.publisher_net, row.currency)}
                                    </td>
                                    <td className="num" style={RIGHT}>
                                        {row.disputes ? `${money(row.disputed, row.currency)} (${row.disputes})` : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

/** Purchases in the range, newest first, filtered by status and publisher, with Refund. */
function Purchases({ purchases, filters, range, publishers, stripe, onRefund }) {
    const rows = purchases?.data ?? [];
    const meta = purchases?.meta;
    const options = [...new Map(publishers.filter((row) => row.organization).map((row) => [row.organization.slug, row.organization.name])).entries()];

    function visit(changes) {
        router.get('/review/finance', query({ ...range, status: filters.status, publisher: filters.publisher, ...changes }), { preserveScroll: true, only: ['purchases', 'filters'] });
    }

    return (
        <section className="card" aria-labelledby="h-purchases" data-purchases>
            <div className="card-head row between wrapflex" style={{ gap: 12 }}>
                <h2 id="h-purchases" className="h3">
                    Purchases
                </h2>
                <div className="row wrapflex" style={{ gap: 12 }}>
                    <label className="field">
                        <span className="label">Status</span>
                        <select className="select" value={filters.status ?? ''} onChange={(e) => visit({ status: e.target.value, page: null })} data-filter="status">
                            <option value="">Any</option>
                            <option value="paid">Paid</option>
                            <option value="refunded">Refunded</option>
                            <option value="disputed">Disputed</option>
                        </select>
                    </label>
                    <label className="field">
                        <span className="label">Publisher</span>
                        <select className="select" value={filters.publisher ?? ''} onChange={(e) => visit({ publisher: e.target.value, page: null })} data-filter="publisher">
                            <option value="">Any</option>
                            {options.map(([slug, name]) => (
                                <option key={slug} value={slug}>
                                    {name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </div>
            {!stripe.configured && (
                <div className="card-body">
                    <Note tone="info">Payments are not set up on this registry, so refunds are off.</Note>
                </div>
            )}
            {rows.length === 0 ? (
                <div className="card-body">
                    <p className="small muted">No purchases{filters.status || filters.publisher ? ' match these filters' : ' in this range'}.</p>
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
                                <th scope="col" className="num" style={RIGHT}>
                                    Gross
                                </th>
                                <th scope="col" className="num" style={RIGHT}>
                                    Fee
                                </th>
                                <th scope="col" className="num" style={RIGHT}>
                                    Refunded
                                </th>
                                <th scope="col">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => {
                                const s = purchaseStatus(row.status);
                                return (
                                    <tr key={row.id} data-purchase={row.id}>
                                        <td className="nowrap">{shortDate(row.created_at)}</td>
                                        <td>
                                            <div className="col" style={{ gap: 2 }}>
                                                <span>{row.extension?.name ?? '—'}</span>
                                                <span className="xs muted">{row.publisher?.name}</span>
                                            </div>
                                        </td>
                                        <td>{row.customer?.name ?? '—'}</td>
                                        <td>
                                            <StatusChip tone={s.tone} icon={s.icon}>
                                                {s.label}
                                            </StatusChip>
                                            {row.dispute?.reason && <div className="xs muted" style={{ marginTop: 4 }}>{row.dispute.reason.replace(/_/g, ' ')}</div>}
                                        </td>
                                        <td className="num" style={RIGHT}>
                                            {money(row.gross, row.currency)}
                                        </td>
                                        <td className="num muted" style={RIGHT}>
                                            {money(row.fee, row.currency)}
                                        </td>
                                        <td className="num muted" style={RIGHT}>
                                            {row.refunded ? `−${money(row.refunded, row.currency)}` : '—'}
                                        </td>
                                        <td style={RIGHT}>
                                            {stripe.configured && row.refundable > 0 && (
                                                <button type="button" className="btn btn-sm" style={{ minHeight: 44 }} onClick={() => onRefund(row)} data-refund={row.id}>
                                                    Refund<span className="sr-only"> {row.extension?.name} for {row.customer?.name}</span>
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            {meta && meta.last_page > 1 && (
                <div className="card-body row between">
                    <button type="button" className="btn btn-sm" disabled={meta.current_page <= 1} onClick={() => visit({ page: meta.current_page - 1 })}>
                        Newer
                    </button>
                    <span className="small muted">
                        Page {meta.current_page} of {meta.last_page}
                    </span>
                    <button type="button" className="btn btn-sm" disabled={meta.current_page >= meta.last_page} onClick={() => visit({ page: meta.current_page + 1 })}>
                        Older
                    </button>
                </div>
            )}
        </section>
    );
}

/** Refund all or part of what is left, through Stripe. */
function RefundDialog({ open, purchase, onClose }) {
    return open && purchase ? <RefundForm key={purchase.id} purchase={purchase} onClose={onClose} /> : null;
}

function RefundForm({ purchase, onClose }) {
    const [amount, setAmount] = useState(toMajor(purchase.refundable));
    const [reason, setReason] = useState(REASONS[0][0]);
    const [note, setNote] = useState('');
    const [error, setError] = useState(null);
    const { run, busy } = useAction();
    const currency = purchase.currency;
    const minor = toMinor(amount) ?? 0;
    const split = refundSplit(purchase, minor);
    const full = purchase.refunded + minor >= purchase.gross;

    function submit() {
        const problem = refundError(amount, purchase.refundable, currency);
        if (problem || busy) {
            setError(problem);
            return;
        }
        run(() => api.post(`/admin/purchases/${purchase.id}/refund`, query({ amount: minor, reason, note: note.trim() })), {
            success: (result) => `Refund of ${money(result?.refund?.amount ?? minor, currency)} sent to Stripe. The purchase updates when Stripe confirms it.`,
            onDone: onClose,
            onError: (e) => setError(e.field('amount') ?? e.message),
        });
    }

    const name = purchase.extension?.name ?? 'this purchase';
    return (
        <Dialog
            open
            onClose={onClose}
            title={`Refund ${name}?`}
            labelledBy="refund-title"
            footer={
                <>
                    <button type="button" className="btn" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-danger-solid" aria-disabled={busy} onClick={submit} data-confirm-refund>
                        {busy ? 'Refunding…' : `Refund ${money(minor, currency)}`}
                    </button>
                </>
            }
        >
            <p className="ink2">
                {purchase.customer?.name ?? 'The customer'} paid {money(purchase.gross, currency)} on {shortDate(purchase.created_at)}
                {purchase.refunded ? `; ${money(purchase.refunded, currency)} is already refunded` : ''}.
            </p>
            <Field label={`Amount (${currency})`} help={`Up to ${money(purchase.refundable, currency)}.`} error={error}>
                {(id, aria) => <input id={id} {...aria} className="input num" inputMode="decimal" data-autofocus value={amount} onChange={(e) => setAmount(e.target.value)} data-refund-amount />}
            </Field>
            <Field label="Reason">
                {(id, aria) => (
                    <select id={id} {...aria} className="select" value={reason} onChange={(e) => setReason(e.target.value)} data-refund-reason>
                        {REASONS.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                )}
            </Field>
            <Field label="Note for the record" optional>
                {(id, aria) => <textarea id={id} {...aria} className="textarea" value={note} onChange={(e) => setNote(e.target.value)} data-refund-note />}
            </Field>
            <p className="small muted" data-refund-split>
                {purchase.publisher?.name ?? 'The publisher'} gives back {money(split.publisher, currency)} and Fleetbase returns {money(split.fee, currency)} of its fee.{' '}
                {full ? 'Once Stripe confirms, the licence is revoked: new installs and updates stop, running instances keep working.' : 'A partial refund leaves the licence active.'}
            </p>
        </Dialog>
    );
}
