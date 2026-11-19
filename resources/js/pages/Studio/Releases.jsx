import { useState } from 'react';
import { Link } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import CopyCommand from '../../components/CopyCommand';
import { checksSummary } from '../../components/Checks';
import { ReleaseStatus, StatusChip } from '../../components/Status';
import { EmptyState, Note } from '../../components/Ui';
import { ListingTabs, StudioHeader } from '../../components/studio/StudioChrome';
import UploadForm from '../../components/studio/UploadForm';
import ReleaseActionDialog, { releaseActions } from '../../components/studio/ReleaseActionDialog';
import { bytes, dateTime, plural } from '../../lib/format';
import { liveRelease } from '../../lib/statuses';

const ARCHIVES = { engine: 'npm engine', api: 'Composer API', dist: 'Prebuilt engine' };

/**
 * A listing's releases: upload a new one (or publish from the CLI), and every release with its
 * status, archives, checks and the actions its state allows.
 */
export default function StudioReleases({ extension, releases = [] }) {
    const [pending, setPending] = useState(null);
    const live = liveRelease(releases);

    return (
        <SiteLayout title={`Releases · ${extension.name}`} section="studio">
            <div className="wrap" style={{ paddingTop: 28, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <StudioHeader extension={extension} title="Releases" sub={`${extension.name}${live ? ` · live: v${live.version}` : ' · nothing live yet'}`} back={`/studio/${extension.slug}`} backLabel={`Back to ${extension.name}`}>
                        <ListingTabs extension={extension} current="releases" />
                    </StudioHeader>

                    {extension.status === 'suspended' && <Note tone="fail">This extension is suspended. New releases cannot be uploaded until Fleetbase lifts the suspension.</Note>}

                    <div className="split">
                        <div className="main">
                            {extension.status !== 'suspended' && <UploadForm extension={extension} />}

                            <section className="card" aria-labelledby="h-releases">
                                <div className="card-head">
                                    <h2 id="h-releases" className="h3">
                                        All releases
                                    </h2>
                                    <span className="small muted">{plural(releases.length, 'release')}</span>
                                </div>
                                {releases.length ? (
                                    <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                                        {releases.map((release) => (
                                            <ReleaseRow key={release.id} extension={extension} release={release} onAction={(action) => setPending({ action, release })} />
                                        ))}
                                    </ul>
                                ) : (
                                    <EmptyState icon="upload" title="No releases yet">
                                        Upload extension.json and the archives above, or run <code>flb publish</code> in the project.
                                    </EmptyState>
                                )}
                            </section>
                        </div>

                        <aside className="rail" aria-label="Publish from the CLI">
                            <section className="card card-pad stack">
                                <h2 className="h4">Publish from the CLI</h2>
                                <p className="small ink2">In the extension’s project, the CLI packs the engine and API, uploads them with extension.json and runs the same checks.</p>
                                <CopyCommand value="flb publish" />
                                <p className="xs muted">
                                    Needs a token with the publish scope. <Link href="/account/tokens">Create one</Link>
                                </p>
                            </section>
                            <Note tone="info">
                                <span className="small">Uploading a version that failed its checks or was rejected replaces it, so the version number can stay.</span>
                            </Note>
                        </aside>
                    </div>
                </div>
            </div>

            <ReleaseActionDialog action={pending?.action} release={pending?.release} extension={extension} onClose={() => setPending(null)} />
        </SiteLayout>
    );
}

function ReleaseRow({ extension, release, onAction }) {
    const summary = checksSummary(release);
    const can = releaseActions(release);
    const href = `/studio/${extension.slug}/releases/${release.id}`;
    const archives = Object.entries(ARCHIVES).filter(([key]) => release.files?.[key]);

    return (
        <li className="sd-rel" data-release={release.version} data-status={release.status}>
            <div className="col" style={{ gap: 8, alignItems: 'flex-start' }}>
                <Link href={href} className="h3 mono" style={{ color: 'var(--ink)' }}>
                    v{release.version}
                </Link>
                <ReleaseStatus status={release.status} />
                <span className="xs muted">
                    {release.channel} · uploaded {dateTime(release.created_at)}
                </span>
            </div>
            <div className="col" style={{ gap: 8, minWidth: 0 }}>
                <div className="row wrapflex" style={{ gap: 8 }}>
                    <span className="small muted">Checks</span>
                    <StatusChip tone={summary.tone} icon={{ ok: 'check', fail: 'x', wait: 'spinner', idle: 'minus' }[summary.tone]}>
                        {summary.label}
                    </StatusChip>
                </div>
                <ul className="col" style={{ gap: 4, listStyle: 'none', margin: 0, padding: 0 }}>
                    {archives.map(([key, label]) => (
                        <li key={key} className="xs" style={{ overflowWrap: 'anywhere' }}>
                            <span className="mono ink2">{release.files[key].original_filename || release.files[key].name}</span>
                            <span className="muted">
                                {' '}
                                · {bytes(release.files[key].size)} · {label}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
            <div className="row wrapflex sd-actions" style={{ justifyContent: 'flex-end', gap: 8 }}>
                <Link href={href} className="btn btn-sm" aria-label={`Open v${release.version}`}>
                    Open
                </Link>
                {can.submit && (
                    <button type="button" className="btn btn-sm btn-primary" onClick={() => onAction('submit')}>
                        {release.status === 'rejected' ? 'Resubmit' : 'Submit'}
                    </button>
                )}
                {can.withdraw && (
                    <button type="button" className="btn btn-sm btn-danger" onClick={() => onAction('withdraw')}>
                        Withdraw
                    </button>
                )}
                {can.yank && (
                    <button type="button" className="btn btn-sm btn-danger" onClick={() => onAction('yank')}>
                        Yank
                    </button>
                )}
            </div>
        </li>
    );
}
