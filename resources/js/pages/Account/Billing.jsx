import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import AccountHeader from '../../components/account/AccountHeader';
import Icon from '../../components/Icon';
import { StatusChip } from '../../components/Status';
import { EmptyState, Note } from '../../components/Ui';
import { api } from '../../lib/api';
import { money, shortDate } from '../../lib/format';
import { purchaseStatus } from '../../lib/statuses';

/** How long the page waits for Stripe's webhook after the buyer returns, polling every 2 s. */
export const FINALIZE_TIMEOUT_MS = 60_000;

/**
 * An organization's billing: what it bought (who bought it, the amount, Stripe's receipt) and its
 * licences with the instances holding a seat. After a website checkout Stripe returns here with
 * `?handoff=`, and the page shows Finalizing until the webhook has created the purchase.
 */
export default function Billing({ organizations = [], organization, purchases = [], licences = [], checkout }) {
    return (
        <SiteLayout title={organization ? `${organization.name} · Billing` : 'Billing'}>
            <div className="wrap" style={{ paddingTop: 40, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <AccountHeader title="Billing" current="billing" description="Extensions your organizations bought, their licences and receipts." />
                    {organizations.length > 1 && (
                        <nav className="seg" aria-label="Organization" style={{ alignSelf: 'flex-start', maxWidth: '100%', overflowX: 'auto' }}>
                            {organizations.map((item) => (
                                <Link key={item.id} href={`/account/billing/${item.slug}`} aria-current={item.slug === organization?.slug ? 'page' : undefined}>
                                    {item.name}
                                </Link>
                            ))}
                        </nav>
                    )}
                    {!organization ? (
                        <EmptyState icon="card" title="No organization to show">
                            Billing is for organization owners, admins and billing members.
                        </EmptyState>
                    ) : (
                        <>
                            {checkout && <Handoff organization={organization} checkout={checkout} />}
                            <Purchases purchases={purchases} />
                            <Licences licences={licences} />
                        </>
                    )}
                </div>
            </div>
        </SiteLayout>
    );
}

/** The checkout the buyer just returned from: Finalizing until the purchase lands. */
function Handoff({ organization, checkout: initial }) {
    const [checkout, setCheckout] = useState(initial);
    const [timedOut, setTimedOut] = useState(false);
    const cancelled = typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('cancelled');

    useEffect(() => {
        setCheckout(initial);
    }, [initial]);

    useEffect(() => {
        if (checkout.status !== 'open' || cancelled) {
            return undefined;
        }
        const started = Date.now();
        const timer = window.setInterval(async () => {
            if (Date.now() - started > FINALIZE_TIMEOUT_MS) {
                window.clearInterval(timer);
                setTimedOut(true);
                return;
            }
            try {
                const next = await api.get(`purchases/${checkout.handoff}`);
                if (next.status !== 'open') {
                    window.clearInterval(timer);
                    setCheckout(next);
                    if (next.status === 'complete') {
                        router.reload({ only: ['purchases', 'licences'] });
                    }
                }
            } catch {
                // Keep polling; a blip should not end the wait.
            }
        }, 2000);
        return () => window.clearInterval(timer);
    }, [checkout.status, checkout.handoff, cancelled]);

    const name = checkout.extension?.name ?? 'the extension';
    if (checkout.status === 'complete') {
        return (
            <Note tone="ok" icon="check" data-handoff="complete">
                <span className="small">
                    {organization.name} now holds a licence for {name}. Install it from your console’s Extensions page.
                </span>
            </Note>
        );
    }
    if (checkout.status === 'expired' || cancelled) {
        return (
            <Note tone="info" data-handoff="expired">
                <span className="small">
                    The checkout for {name} was not completed. {checkout.extension && <Link href={`/extensions/${checkout.extension.slug}`}>Try again</Link>}
                </span>
            </Note>
        );
    }
    return (
        <Note tone="info" icon="spinner" role="status" data-handoff="open">
            <span className="small">
                {timedOut ? `Stripe has not confirmed the payment for ${name} yet. It appears here once it does; you can leave this page.` : `Finalizing your purchase of ${name}…`}
            </span>
        </Note>
    );
}

function Purchases({ purchases }) {
    return (
        <section className="card" aria-labelledby="h-purchases" data-purchases>
            <div className="card-head">
                <h2 id="h-purchases" className="h3">
                    Purchases
                </h2>
            </div>
            {purchases.length === 0 ? (
                <div className="card-body">
                    <p className="small muted">
                        Nothing bought yet. Paid extensions are bought from their page or in your console. <Link href="/">Browse extensions</Link>
                    </p>
                </div>
            ) : (
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Extension</th>
                                <th scope="col">Bought by</th>
                                <th scope="col">Status</th>
                                <th scope="col" style={{ textAlign: 'right' }}>
                                    Amount
                                </th>
                                <th scope="col">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            {purchases.map((purchase) => {
                                const s = purchaseStatus(purchase.status);
                                return (
                                    <tr key={purchase.id} data-purchase={purchase.id}>
                                        <td className="nowrap">{shortDate(purchase.created_at)}</td>
                                        <td>{purchase.extension ? <Link href={`/extensions/${purchase.extension.slug}`}>{purchase.extension.name}</Link> : '—'}</td>
                                        <td>{purchase.bought_by?.name ?? (purchase.source === 'instance' ? 'From a console' : '—')}</td>
                                        <td>
                                            <StatusChip tone={s.tone} icon={s.icon}>
                                                {s.label}
                                            </StatusChip>
                                        </td>
                                        <td className="num" style={{ textAlign: 'right' }}>
                                            {money(purchase.amount_total, purchase.currency)}
                                            {purchase.amount_tax > 0 && <span className="xs muted"> incl. {money(purchase.amount_tax, purchase.currency)} tax</span>}
                                        </td>
                                        <td>
                                            {purchase.receipt_url ? (
                                                <a href={purchase.receipt_url} target="_blank" rel="noreferrer">
                                                    Receipt <Icon name="external" size={14} />
                                                </a>
                                            ) : (
                                                <span className="muted">—</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function Licences({ licences }) {
    return (
        <section className="card" aria-labelledby="h-licences" data-licences>
            <div className="card-head">
                <h2 id="h-licences" className="h3">
                    Licences
                </h2>
            </div>
            {licences.length === 0 ? (
                <div className="card-body">
                    <p className="small muted">No licences yet. Installing a free extension or buying a paid one adds one here.</p>
                </div>
            ) : (
                <ul className="col" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                    {licences.map((licence) => (
                        <li key={licence.id} className="card-body stack" style={{ borderTop: '1px solid var(--line)', gap: 8 }} data-licence={licence.id}>
                            <div className="row between wrapflex" style={{ gap: 8 }}>
                                <span className="h4">{licence.extension?.name ?? 'Removed extension'}</span>
                                <span className="row" style={{ gap: 8 }}>
                                    <StatusChip tone="idle">{licence.kind === 'paid' ? 'Paid' : 'Free'}</StatusChip>
                                    {licence.status === 'revoked' ? (
                                        <StatusChip tone="fail" icon="x">
                                            Revoked{licence.revoked_reason === 'refunded' ? ' (refunded)' : ''}
                                        </StatusChip>
                                    ) : (
                                        <StatusChip tone="ok" icon="check">
                                            Active
                                        </StatusChip>
                                    )}
                                </span>
                            </div>
                            <span className="small ink2">
                                {licence.max_activations == null ? `${licence.seats_used ?? 0} instances, no limit` : `${licence.seats_used ?? 0} of ${licence.max_activations} instances`}
                            </span>
                            {licence.activations?.length > 0 && (
                                <ul className="col small" style={{ gap: 4, margin: 0, paddingLeft: 18 }}>
                                    {licence.activations.map((activation) => (
                                        <li key={activation.id}>
                                            {activation.instance?.name ?? 'Instance'}
                                            {activation.instance?.host && <span className="muted"> · {activation.instance.host}</span>}
                                            {activation.release && <span className="muted"> · v{activation.release}</span>}
                                            <span className="muted"> · since {shortDate(activation.activated_at)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
