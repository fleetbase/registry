import { useEffect, useState } from 'react';
import { Link, usePoll } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Icon from '../../components/Icon';
import CopyCommand from '../../components/CopyCommand';
import Checks, { checkRows, checksSummary } from '../../components/Checks';
import Route, { releaseStops } from '../../components/Route';
import { ReleaseStatus, StatusChip } from '../../components/Status';
import { Note, SafeHtml } from '../../components/Ui';
import { StudioHeader } from '../../components/studio/StudioChrome';
import ReleaseActionDialog, { releaseActions } from '../../components/studio/ReleaseActionDialog';
import { bytes, shortDate } from '../../lib/format';

const FILES = [
    ['engine', 'npm engine'],
    ['api', 'Composer API'],
    ['dist', 'Prebuilt engine'],
];

/**
 * One release: its route, the eight checks (polled every 2 s while they run), what to do next
 * when they fail, the way to review and submit when they pass, its files and notes.
 */
export default function StudioRelease({ extension, release }) {
    const checking = release.status === 'checking';
    const { start, stop } = usePoll(2000, { only: ['release'] }, { autoStart: false });
    const [pending, setPending] = useState(null);
    const can = releaseActions(release);

    useEffect(() => {
        if (checking) {
            start();
        } else {
            stop();
        }
        return () => stop();
    }, [checking, start, stop]);

    const summary = checksSummary(release);
    const failed = checkRows(release).filter((row) => row.state === 'fail');
    const passed = release.status === 'uploaded';
    const stops = releaseStops(release, { submittedAt: shortDate(release.submission?.submitted_at) });
    const reviewHref = `/studio/${extension.slug}/review`;

    return (
        <SiteLayout title={`v${release.version} · ${extension.name}`} section="studio">
            <div className="wrap" style={{ paddingTop: 28, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <StudioHeader extension={extension} title={`Release v${release.version}`} sub={`${extension.name} · ${release.channel} channel`} back={`/studio/${extension.slug}/releases`} backLabel={`Back to ${extension.name} releases`}>
                        <ReleaseStatus status={release.status} aria-live="polite" />
                    </StudioHeader>

                    <div className="card card-pad">
                        <Route stops={stops} label={`Release v${release.version} progress`} />
                    </div>

                    {checking && release.submission?.requested && (
                        <Note tone="info">
                            It is submitted for review as soon as the checks pass.{' '}
                            <button type="button" className="sd-linkbtn" onClick={() => setPending('withdraw')}>
                                Don’t submit
                            </button>
                        </Note>
                    )}

                    <div className="split">
                        <section className="main card" aria-labelledby="h-checks">
                            <div className="card-head">
                                <h2 id="h-checks" className="h3">
                                    Automated checks
                                </h2>
                                <StatusChip tone={summary.tone} icon={{ ok: 'check', fail: 'x', wait: 'spinner', idle: 'minus' }[summary.tone]} aria-live="polite" data-summary>
                                    {summary.label}
                                </StatusChip>
                            </div>
                            <div className="card-body">
                                <Checks release={release} />
                            </div>
                            {release.status === 'failed_checks' && (
                                <div className="card-body" style={{ borderTop: '1px solid var(--line)' }} data-next="fix">
                                    <div className="stack">
                                        <h3 className="h4">What to do next</h3>
                                        <ol className="ink2" style={{ margin: 0, paddingLeft: 20, display: 'flex', flexDirection: 'column', gap: 6 }}>
                                            <li>Fix what {failed.length === 1 ? `the ${failed[0].label.toLowerCase()} check` : `the ${failed.length} failed checks`} found, listed above.</li>
                                            {failed.some((row) => row.key === 'secrets_scan') && <li>Rotate any exposed key with its provider. Treat it as public.</li>}
                                            <li>Upload v{release.version} again. A release that failed its checks is replaced, so the version number can stay.</li>
                                        </ol>
                                        <CopyCommand value="flb publish" />
                                        <Link href={`/studio/${extension.slug}/releases`} className="btn" style={{ alignSelf: 'flex-start' }}>
                                            <Icon name="upload" size={16} />
                                            Upload again
                                        </Link>
                                    </div>
                                </div>
                            )}
                            {passed && (
                                <div className="card-body row between wrapflex" style={{ borderTop: '1px solid var(--line)', gap: 16 }} data-next="submit">
                                    <span className="ink2">All checks passed. Submit when the listing is ready; first releases go to a Fleetbase reviewer.</span>
                                    <Link href={reviewHref} className="btn btn-primary">
                                        Review and submit
                                    </Link>
                                </div>
                            )}
                            {['submitted', 'in_review', 'approved', 'rejected'].includes(release.status) && (
                                <div className="card-body row between wrapflex" style={{ borderTop: '1px solid var(--line)', gap: 16 }}>
                                    <span className="ink2">{release.status === 'rejected' ? 'The reviewer asked for changes.' : 'This release is with Fleetbase.'}</span>
                                    <Link href={reviewHref} className={`btn ${release.status === 'rejected' ? 'btn-primary' : ''}`}>
                                        {release.status === 'rejected' ? 'See feedback' : 'View review'}
                                    </Link>
                                </div>
                            )}
                        </section>

                        <aside className="rail" aria-label="Release files">
                            <section className="card card-pad stack">
                                <h2 className="h4">Files</h2>
                                <div className="sd-file">
                                    <span className="fi" aria-hidden="true">
                                        <Icon name="file" size={16} />
                                    </span>
                                    <span className="col grow" style={{ gap: 0, minWidth: 0 }}>
                                        <span className="mono small">extension.json</span>
                                        <span className="xs muted">
                                            version {release.version} · {release.channel}
                                        </span>
                                    </span>
                                </div>
                                {FILES.filter(([key]) => release.files?.[key]).map(([key, label]) => (
                                    <div key={key} className="sd-file" data-file={key}>
                                        <span className="fi" aria-hidden="true">
                                            <Icon name="box" size={16} />
                                        </span>
                                        <span className="col grow" style={{ gap: 0, minWidth: 0 }}>
                                            <span className="mono small" style={{ overflowWrap: 'anywhere' }}>
                                                {release.files[key].original_filename || release.files[key].name}
                                            </span>
                                            <span className="xs muted">
                                                {bytes(release.files[key].size)} · {label}
                                            </span>
                                        </span>
                                    </div>
                                ))}
                            </section>
                            <section className="card card-pad stack">
                                <h2 className="h4">Release notes</h2>
                                {release.notes ? <SafeHtml html={release.notes} className="small ink2" /> : <p className="small muted">No notes for this release.</p>}
                            </section>
                            {checking && (
                                <Note tone="info">
                                    <span className="small">You can leave this page. Checks keep running and the result waits for you in Studio.</span>
                                </Note>
                            )}
                            {can.yank && (
                                <button type="button" className="btn btn-danger btn-sm" style={{ alignSelf: 'flex-start' }} onClick={() => setPending('yank')}>
                                    Yank v{release.version}
                                </button>
                            )}
                        </aside>
                    </div>
                </div>
            </div>
            <ReleaseActionDialog action={pending} release={pending ? release : null} extension={extension} onClose={() => setPending(null)} />
        </SiteLayout>
    );
}
