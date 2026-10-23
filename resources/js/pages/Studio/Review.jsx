import { useState } from 'react';
import { Link } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Icon from '../../components/Icon';
import CopyCommand from '../../components/CopyCommand';
import Timeline from '../../components/Timeline';
import { checksSummary } from '../../components/Checks';
import Route, { releaseStops } from '../../components/Route';
import { ReleaseStatus, StatusChip } from '../../components/Status';
import { EmptyState, Note } from '../../components/Ui';
import { ListingTabs, REQUIREMENTS, StudioHeader } from '../../components/studio/StudioChrome';
import ReleaseActionDialog from '../../components/studio/ReleaseActionDialog';
import { dateTime, initials, shortDate } from '../../lib/format';
import { liveRelease } from '../../lib/statuses';

/** The newest "changes requested" decision on a release, or null. */
export function latestRejection(release) {
    const rejections = (release?.reviews ?? []).filter((entry) => entry.action === 'reject');
    return rejections.sort((a, b) => new Date(a.created_at) - new Date(b.created_at)).at(-1) ?? null;
}

/**
 * Submit the open release for review, or follow it: ready to submit (with what the listing still
 * needs), checks running or failed, with Fleetbase (and why a person reviews it), approved, or
 * changes requested (the reviewer's message, the six requirements, and how to send it back).
 */
export default function StudioReview({ extension, releases = [], candidate = null }) {
    const [action, setAction] = useState(null);
    const live = liveRelease(releases);
    const status = candidate?.status;
    const sub = [live ? `Live: v${live.version}` : 'Not live yet', extension.organization?.name].filter(Boolean).join(' · ');
    const title = candidate ? `${extension.name} v${candidate.version}` : extension.name;
    const stops = candidate ? releaseStops(candidate, { submittedAt: shortDate(candidate.submission?.submitted_at) }) : null;

    return (
        <SiteLayout title={`Review · ${extension.name}`} section="studio">
            <div className="wrap" style={{ paddingTop: 28, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <StudioHeader extension={extension} title={title} sub={sub} back={`/studio/${extension.slug}`} backLabel={`Back to ${extension.name}`}>
                        {candidate && <ReleaseStatus status={status} aria-live="polite" />}
                    </StudioHeader>
                    <ListingTabs extension={extension} current="review" />

                    {!candidate ? (
                        <div className="card">
                            <EmptyState
                                icon="upload"
                                title={live ? `v${live.version} is live. Nothing is in review.` : 'Nothing to review yet'}
                                actions={
                                    <Link href={`/studio/${extension.slug}/releases`} className="btn btn-primary">
                                        Upload a release
                                    </Link>
                                }
                            >
                                Upload a release first. When its checks pass, submit it here.
                            </EmptyState>
                        </div>
                    ) : (
                        <>
                            <div className="card card-pad">
                                <Route stops={stops} label={`Release v${candidate.version} progress`} />
                            </div>
                            <div className="split">
                                <div className="main">
                                    {status === 'rejected' && <Rejected candidate={candidate} />}
                                    {(status === 'checking' || status === 'failed_checks') && <ChecksPending extension={extension} candidate={candidate} />}
                                    {status === 'uploaded' && <ReadyToSubmit extension={extension} candidate={candidate} live={live} onSubmit={() => setAction('submit')} />}
                                    {(status === 'submitted' || status === 'in_review') && <WithFleetbase candidate={candidate} onWithdraw={() => setAction('withdraw')} />}
                                    {status === 'approved' && <Approved candidate={candidate} onWithdraw={() => setAction('withdraw')} />}

                                    <section className="card" aria-labelledby="h-tl">
                                        <div className="card-head">
                                            <h2 id="h-tl" className="h3">
                                                This release
                                            </h2>
                                            <Link href={`/studio/${extension.slug}/history`} className="small">
                                                Full history
                                            </Link>
                                        </div>
                                        <div className="card-body">
                                            <Timeline entries={candidate.reviews ?? []} itemLabels={REQUIREMENTS} />
                                        </div>
                                    </section>
                                </div>
                                <aside className={`rail ${status === 'rejected' ? 'rail-first' : ''}`}>
                                    {status === 'rejected' ? <SendItBack extension={extension} candidate={candidate} onResubmit={() => setAction('submit')} /> : <WhatReviewersCheck />}
                                </aside>
                            </div>
                        </>
                    )}
                </div>
            </div>
            <ReleaseActionDialog action={action} release={action ? candidate : null} extension={extension} onClose={() => setAction(null)} />
        </SiteLayout>
    );
}

function ReadyToSubmit({ extension, candidate, live, onSubmit }) {
    const summary = checksSummary(candidate);
    const missing = extension.readiness?.missing ?? [];
    return (
        <section className="card" aria-labelledby="h-ready" data-state="ready">
            <div className="card-body stack">
                <h2 id="h-ready" className="h3">
                    Ready to submit
                </h2>
                <ul className="col" style={{ gap: 8, listStyle: 'none', margin: 0, padding: 0 }}>
                    <li className="row" style={{ gap: 10 }}>
                        <StatusChip tone="ok" icon="check">
                            Done
                        </StatusChip>
                        <span>Automated checks: {summary.label.toLowerCase()}</span>
                    </li>
                    <li className="row" style={{ gap: 10 }} data-listing-ready={missing.length === 0}>
                        {missing.length === 0 ? (
                            <>
                                <StatusChip tone="ok" icon="check">
                                    Done
                                </StatusChip>
                                <span>Listing complete</span>
                            </>
                        ) : (
                            <>
                                <StatusChip tone="you" icon="alert">
                                    To do
                                </StatusChip>
                                <span>
                                    The listing needs {missing.length} more {missing.length === 1 ? 'thing' : 'things'}
                                </span>
                            </>
                        )}
                    </li>
                </ul>
                {missing.length > 0 && (
                    <Note tone="you" data-missing>
                        <ul style={{ margin: 0, paddingLeft: 18 }}>
                            {missing.map((message) => (
                                <li key={message} className="small">
                                    {message}
                                </li>
                            ))}
                        </ul>
                        <Link href={`/studio/${extension.slug}`} className="small">
                            Finish the listing
                        </Link>
                    </Note>
                )}
                <div className="row between wrapflex">
                    <span className="small muted">
                        {live
                            ? 'Updates that change packages, price or permissions go to a Fleetbase reviewer; other updates publish straight after their checks.'
                            : 'A Fleetbase reviewer looks at every first release.'}
                    </span>
                    <button type="button" className="btn btn-primary btn-lg" onClick={onSubmit} disabled={missing.length > 0}>
                        Submit for review
                    </button>
                </div>
            </div>
        </section>
    );
}

function ChecksPending({ extension, candidate }) {
    const running = candidate.status === 'checking';
    return (
        <section className="card" aria-labelledby="h-checks" data-state={candidate.status}>
            <div className="card-body stack">
                <div className="row" style={{ gap: 14, alignItems: 'flex-start' }}>
                    <span className="empty-glyph" aria-hidden="true" style={running ? undefined : { background: 'var(--fail-bg)', color: 'var(--fail)' }}>
                        <Icon name={running ? 'spinner' : 'x'} size={26} />
                    </span>
                    <div className="col" style={{ gap: 6 }}>
                        <h2 id="h-checks" className="h3">
                            {running ? 'Checks are running' : 'Checks failed'}
                        </h2>
                        <p className="ink2">{running ? 'Submit when all eight automated checks pass.' : `Fix what the checks found and upload v${candidate.version} again; it replaces this release.`}</p>
                    </div>
                </div>
                <Link href={`/studio/${extension.slug}/releases/${candidate.id}`} className={`btn ${running ? '' : 'btn-primary'}`} style={{ alignSelf: 'flex-start' }}>
                    {running ? 'Watch the checks' : 'See what failed'}
                </Link>
            </div>
        </section>
    );
}

function WithFleetbase({ candidate, onWithdraw }) {
    const reasons = Object.values(candidate.review?.reasons ?? {});
    return (
        <section className="card" aria-labelledby="h-wait" data-state="waiting">
            <div className="card-body stack">
                <div className="row" style={{ gap: 14, alignItems: 'flex-start' }}>
                    <span className="empty-glyph" aria-hidden="true">
                        <Icon name="clock" size={26} />
                    </span>
                    <div className="col" style={{ gap: 6 }}>
                        <h2 id="h-wait" className="h3">
                            With Fleetbase. Nothing for you to do.
                        </h2>
                        <p className="ink2">A reviewer checks the release and the listing against six requirements. Reviews usually take a few business days; you’ll get an email either way.</p>
                    </div>
                </div>
                {reasons.length > 0 && (
                    <Note tone="info" data-review-reasons>
                        <div className="col" style={{ gap: 4 }}>
                            <strong>Why a person reviews this release</strong>
                            <ul style={{ margin: 0, paddingLeft: 18 }}>
                                {reasons.map((reason) => (
                                    <li key={reason} className="small">
                                        {reason}
                                    </li>
                                ))}
                            </ul>
                            <span className="small">Updates that change packages, price or permissions are reviewed; other updates publish straight after their checks.</span>
                        </div>
                    </Note>
                )}
                <div className="row wrapflex between">
                    <span className="small muted">You can keep editing the listing. Changing the release means withdrawing it.</span>
                    <button type="button" className="btn btn-danger" onClick={onWithdraw}>
                        Withdraw from review
                    </button>
                </div>
            </div>
        </section>
    );
}

function Approved({ candidate, onWithdraw }) {
    return (
        <section className="card" aria-labelledby="h-approved" data-state="approved">
            <div className="card-body stack">
                <h2 id="h-approved" className="h3">
                    Approved. Publishing next.
                </h2>
                <p className="ink2">v{candidate.version} passed review. It goes live in the catalog, npm and Composer when Fleetbase publishes it; nothing for you to do.</p>
                <div className="row wrapflex between">
                    <span className="small muted">Need to change something first? Withdraw it, then upload and submit again.</span>
                    <button type="button" className="btn btn-danger" onClick={onWithdraw}>
                        Withdraw
                    </button>
                </div>
            </div>
        </section>
    );
}

function Rejected({ candidate }) {
    const decision = latestRejection(candidate);
    const items = new Map((decision?.items ?? []).map((item) => [item.code, item]));
    const codes = Object.keys(REQUIREMENTS);
    const failed = codes.filter((code) => items.get(code)?.passed === false);
    const passedCount = codes.filter((code) => items.get(code)?.passed === true).length;
    const ordered = [...failed, ...codes.filter((code) => !failed.includes(code))];
    const reviewer = decision?.by?.name ?? 'Fleetbase review team';

    return (
        <>
            <section className="card" aria-labelledby="h-fb" data-state="rejected">
                <div className="card-body stack">
                    <div className="row" style={{ gap: 12 }}>
                        <span className="avatar" aria-hidden="true">
                            {decision?.by?.name ? initials(decision.by.name) : 'FB'}
                        </span>
                        <div className="col" style={{ gap: 0 }}>
                            <h2 id="h-fb" className="h4">
                                {reviewer}
                            </h2>
                            <span className="small muted">{decision ? dateTime(decision.created_at) : ''}</span>
                        </div>
                    </div>
                    <div className="quote" style={{ fontSize: 15, whiteSpace: 'pre-line' }} data-reason>
                        {decision?.reason || 'The reviewer asked for changes without a message. The requirements below show what to fix.'}
                    </div>
                </div>
            </section>

            <section className="card" aria-labelledby="h-req">
                <div className="card-head">
                    <h2 id="h-req" className="h3">
                        Requirements
                    </h2>
                    <span className="small muted">
                        {failed.length} to fix · {passedCount} passed
                    </span>
                </div>
                <ul className="card-body" style={{ listStyle: 'none', margin: 0 }}>
                    {ordered.map((code) => {
                        const item = items.get(code);
                        const isFailed = item?.passed === false;
                        return (
                            <li key={code} className={`sd-req ${isFailed ? 'failed' : ''}`} data-requirement={code} data-passed={item ? String(item.passed) : 'unknown'}>
                                <span className="sd-code">{code}</span>
                                <div className="col" style={{ gap: 4 }}>
                                    <span className="h4">{REQUIREMENTS[code]}</span>
                                    {item?.note && <span className={`small ${isFailed ? 'ink2' : 'muted'}`}>{item.note}</span>}
                                </div>
                                {isFailed ? (
                                    <StatusChip tone="fail" icon="x">
                                        Fix
                                    </StatusChip>
                                ) : item?.passed ? (
                                    <StatusChip tone="ok" icon="check">
                                        Passed
                                    </StatusChip>
                                ) : (
                                    <StatusChip tone="idle" icon="minus">
                                        Not noted
                                    </StatusChip>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </section>
        </>
    );
}

function SendItBack({ extension, candidate, onResubmit }) {
    const missing = extension.readiness?.missing ?? [];
    return (
        <>
            <section className="card" style={{ boxShadow: 'var(--e2)' }} aria-labelledby="h-back">
                <div className="card-body stack">
                    <h2 id="h-back" className="h4">
                        Send it back
                    </h2>
                    <ol className="col" style={{ gap: 12, listStyle: 'none', margin: 0, padding: 0 }}>
                        <li className="row" style={{ gap: 10, alignItems: 'flex-start' }}>
                            <span className="count" aria-hidden="true">
                                1
                            </span>
                            <span className="small">Fix the code{missing.length ? ' and the listing' : ''}. {missing.length ? `The listing needs ${missing.length} more.` : 'The listing needs nothing.'}</span>
                        </li>
                        <li className="row" style={{ gap: 10, alignItems: 'flex-start' }}>
                            <span className="count" aria-hidden="true">
                                2
                            </span>
                            <span className="small">Upload v{candidate.version} again. A rejected release is replaced, so you keep the version.</span>
                        </li>
                        <li className="row" style={{ gap: 10, alignItems: 'flex-start' }}>
                            <span className="count" aria-hidden="true">
                                3
                            </span>
                            <span className="small">Submit. It goes back to the review queue.</span>
                        </li>
                    </ol>
                    {missing.length > 0 && (
                        <Note tone="you">
                            <ul style={{ margin: 0, paddingLeft: 18 }}>
                                {missing.map((message) => (
                                    <li key={message} className="small">
                                        {message}
                                    </li>
                                ))}
                            </ul>
                            <Link href={`/studio/${extension.slug}`} className="small">
                                Finish the listing
                            </Link>
                        </Note>
                    )}
                    <Link href={`/studio/${extension.slug}/releases`} className="btn btn-primary">
                        Upload fixed build
                    </Link>
                    <CopyCommand value="flb publish" />
                    <button type="button" className="btn btn-quiet btn-sm" onClick={onResubmit} disabled={missing.length > 0}>
                        Resubmit without changes
                    </button>
                </div>
            </section>
            <Link href={`/studio/${extension.slug}/history`} className="small">
                Full history
            </Link>
        </>
    );
}

function WhatReviewersCheck() {
    return (
        <section className="card card-pad stack" aria-labelledby="h-reqs">
            <h2 id="h-reqs" className="h4">
                What reviewers check
            </h2>
            <ul className="small ink2" style={{ margin: 0, padding: 0, listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 6 }}>
                {Object.entries(REQUIREMENTS).map(([code, label]) => (
                    <li key={code}>
                        <span className="mono">{code}</span> · {label}
                    </li>
                ))}
            </ul>
        </section>
    );
}
