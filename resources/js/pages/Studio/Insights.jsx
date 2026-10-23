import SiteLayout from '../../layouts/SiteLayout';
import { StatusChip } from '../../components/Status';
import { StudioNav } from '../../components/studio/StudioChrome';

/** Analytics and payouts, both coming soon. No numbers until there are real ones. */
export default function StudioInsights() {
    return (
        <SiteLayout title="Insights" section="studio">
            <div className="wrap" style={{ paddingTop: 36, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <div className="row between wrapflex" style={{ gap: 16 }}>
                        <h1 className="h1">Insights</h1>
                        <StudioNav current="insights" />
                    </div>
                    <div className="grid2" style={{ alignItems: 'stretch' }}>
                        <section className="card card-pad stack" aria-labelledby="h-an">
                            <StatusChip tone="idle" icon="clock" style={{ alignSelf: 'flex-start' }}>
                                Coming soon
                            </StatusChip>
                            <h2 id="h-an" className="h2">
                                Analytics
                            </h2>
                            <p className="ink2">Installs, uninstalls and active instances per extension over time, split by version, so you know when it is safe to drop support for an old console.</p>
                            <div className="sd-ghost" style={{ height: 200 }} aria-hidden="true">
                                <svg viewBox="0 0 400 140" preserveAspectRatio="none" fill="none" stroke="currentColor" strokeWidth="2" strokeDasharray="4 6">
                                    <path d="M0 120 C 60 110, 90 90, 140 92 S 230 60, 280 52 S 360 30, 400 20" />
                                </svg>
                            </div>
                            <p className="small muted">Today: each extension’s install count is on its public page.</p>
                        </section>
                        <section className="card card-pad stack" aria-labelledby="h-pay">
                            <StatusChip tone="idle" icon="clock" style={{ alignSelf: 'flex-start' }}>
                                Coming soon
                            </StatusChip>
                            <h2 id="h-pay" className="h2">
                                Payouts
                            </h2>
                            <p className="ink2">Sell extensions with a one-time licence. Payments go to your organization’s Stripe Connect account; Fleetbase keeps a facilitator fee and shows every sale, refund and payout here.</p>
                            <ol className="col" style={{ gap: 10, listStyle: 'none', margin: 0, padding: 0 }}>
                                {['Connect a Stripe account for your organization', 'Set a price on any listing', 'Operators buy in their console; you’re paid on Stripe’s schedule'].map((step, index) => (
                                    <li key={step} className="row" style={{ gap: 10 }}>
                                        <span className="count" aria-hidden="true">
                                            {index + 1}
                                        </span>
                                        <span className="small">{step}</span>
                                    </li>
                                ))}
                            </ol>
                            <div className="row wrapflex">
                                <button type="button" className="btn" disabled aria-describedby="payouts-when">
                                    Connect Stripe
                                </button>
                                <span id="payouts-when" className="small muted">
                                    Available when payouts open
                                </span>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </SiteLayout>
    );
}
