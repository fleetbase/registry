import { useState } from 'react';
import { Link } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ExtensionIcon from '../../components/ExtensionIcon';
import { StatusChip } from '../../components/Status';
import { EmptyState } from '../../components/Ui';
import { LiftDialog, PublishDialog, ReviewTabs } from '../../components/review/Parts';
import { isFirstRelease } from '../../components/review/queue';
import { dateTime, plural, shortDate } from '../../lib/format';

const ROW = { display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap', padding: '18px 24px' };

/**
 * To publish: releases approved without “publish at once”, each with Publish, and suspended
 * listings with their reason and Lift suspension.
 */
export default function Pending({ releases, approvers = {}, suspended = [], counts = {} }) {
    const approved = releases?.data ?? [];
    const [publishing, setPublishing] = useState(null);
    const [lifting, setLifting] = useState(null);

    return (
        <SiteLayout title="To publish" section="review">
            <div className="wrap" style={{ paddingTop: 36, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <div className="row between wrapflex" style={{ gap: 16 }}>
                        <div className="col" style={{ gap: 6 }}>
                            <h1 className="h1">To publish</h1>
                            <p className="ink2">Approved without “publish at once”. Publishing makes it the latest version in the catalog, npm and Composer.</p>
                        </div>
                        <ReviewTabs current="publish" counts={counts} />
                    </div>

                    <section className="card" aria-labelledby="h-ready">
                        <div className="card-head">
                            <h2 id="h-ready" className="h3">
                                Approved
                            </h2>
                            <span className="small muted">{plural(approved.length, 'release')}</span>
                        </div>
                        {approved.length ? (
                            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                                {approved.map((release, index) => {
                                    const extension = release.extension ?? {};
                                    const approver = approvers[release.id];
                                    return (
                                        <li key={release.id} style={{ ...ROW, borderTop: index ? '1px solid var(--line)' : 0 }} data-approved={release.id}>
                                            <ExtensionIcon extension={extension} />
                                            <div className="col grow" style={{ gap: 2, minWidth: 200 }}>
                                                <span className="h4">
                                                    {extension.name} <span className="mono small muted">v{release.version}</span>
                                                </span>
                                                <span className="small muted">
                                                    {[extension.organization?.name, isFirstRelease(release) ? 'first release' : 'update', approver?.name && `approved by ${approver.name}${approver.at ? `, ${dateTime(approver.at)}` : ''}`].filter(Boolean).join(' · ')}
                                                </span>
                                            </div>
                                            <StatusChip tone="wait" icon="check">
                                                Approved, not live
                                            </StatusChip>
                                            <div className="row">
                                                <Link href={`/review/releases/${release.id}`} className="btn btn-sm" style={{ minHeight: 44 }}>
                                                    Open<span className="sr-only"> {extension.name}</span>
                                                </Link>
                                                <button type="button" className="btn btn-sm btn-primary" style={{ minHeight: 44 }} onClick={() => setPublishing(release)} data-publish={release.id}>
                                                    Publish<span className="sr-only"> {extension.name}</span>
                                                </button>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : (
                            <EmptyState icon="check" title="Nothing waiting to go live">
                                Releases approved without “publish at once” appear here.
                            </EmptyState>
                        )}
                    </section>

                    <section className="card" aria-labelledby="h-susp">
                        <div className="card-head">
                            <h2 id="h-susp" className="h3">
                                Suspended listings
                            </h2>
                            <span className="small muted">{plural(suspended.length, 'listing')}</span>
                        </div>
                        {suspended.length ? (
                            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                                {suspended.map((listing, index) => {
                                    const s = listing.suspension ?? {};
                                    return (
                                        <li key={listing.id} style={{ ...ROW, borderTop: index ? '1px solid var(--line)' : 0 }} data-suspended={listing.slug}>
                                            <ExtensionIcon extension={listing} />
                                            <div className="col grow" style={{ gap: 2, minWidth: 200 }}>
                                                <span className="h4">{listing.name}</span>
                                                <span className="small muted">{[listing.organization?.name, s.at && `suspended ${shortDate(s.at)}${s.by_name ? ` by ${s.by_name}` : ''}`].filter(Boolean).join(' · ')}</span>
                                                {s.reason && (
                                                    <span className="quote small" style={{ marginTop: 6, whiteSpace: 'pre-line' }}>
                                                        {s.reason}
                                                    </span>
                                                )}
                                            </div>
                                            <StatusChip tone="fail" icon="ban">
                                                Suspended
                                            </StatusChip>
                                            <button type="button" className="btn btn-sm" style={{ minHeight: 44 }} onClick={() => setLifting(listing)} data-lift={listing.slug}>
                                                Lift suspension<span className="sr-only"> of {listing.name}</span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : (
                            <EmptyState icon="shield" title="No suspended listings">
                                Listings you suspend from a review page appear here until the suspension is lifted.
                            </EmptyState>
                        )}
                    </section>
                </div>
            </div>
            <PublishDialog open={Boolean(publishing)} release={publishing} onClose={() => setPublishing(null)} />
            <LiftDialog open={Boolean(lifting)} extension={lifting} onClose={() => setLifting(null)} />
        </SiteLayout>
    );
}
