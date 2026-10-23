import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Checks from '../../components/Checks';
import ExtensionIcon from '../../components/ExtensionIcon';
import Icon from '../../components/Icon';
import Timeline from '../../components/Timeline';
import { ReleaseStatus, StatusChip } from '../../components/Status';
import { Verified } from '../../components/Trust';
import { Note, SafeHtml, Segmented } from '../../components/Ui';
import { DecidedCard, DecisionBar, DecisionCard, DecisionDialogs, useDecision } from '../../components/review/DecisionRail';
import { LiftDialog, PublishDialog, SuspendDialog, useAction } from '../../components/review/Parts';
import { canDecide } from '../../components/review/decision';
import { isFirstRelease } from '../../components/review/queue';
import { api } from '../../lib/api';
import { bytes, dateTime, isPaid, priceLabel, timeAgo } from '../../lib/format';
import { HOSTINGS } from '../../lib/statuses';

const TABS = [
    { value: 'checks', label: 'Checks' },
    { value: 'manifest', label: 'Manifest' },
    { value: 'listing', label: 'Listing preview' },
    { value: 'timeline', label: 'Timeline' },
];

const ARCHIVES = [
    ['engine', 'Engine'],
    ['api', 'API'],
    ['dist', 'Prebuilt'],
];

/**
 * One release under review: the evidence (checks and archives, manifest, listing preview,
 * timeline) and the decision rail (R-items, message, approve / request changes).
 */
export default function ReviewRelease({ release, reviewItems = [], suspension = null }) {
    return <ReviewBody key={release.id} release={release} reviewItems={reviewItems} suspension={suspension} />;
}

function ReviewBody({ release, reviewItems, suspension }) {
    const { auth } = usePage().props;
    const extension = release.extension ?? {};
    const organization = extension.organization;
    const [tab, setTab] = useState('checks');
    const [dialog, setDialog] = useState(null);
    const decision = useDecision(release, reviewItems);
    const { run, busy } = useAction();
    const decidable = canDecide(release.status);
    const suspended = extension.status === 'suspended';
    const itemLabels = Object.fromEntries(reviewItems.map((item) => [item.code, item.label]));
    const claim = [...(release.reviews ?? [])].reverse().find((entry) => entry.action === 'claim');
    const mine = claim?.by?.id && claim.by.id === auth?.account?.id;
    const reasons = Object.values(Array.isArray(release.review?.reasons) ? {} : (release.review?.reasons ?? {}));
    const submittedAt = release.submission?.submitted_at;

    function startReview() {
        if (busy) {
            return;
        }
        run(() => api.post(`/admin/releases/${release.id}/claim`), { success: `You’re reviewing ${extension.name} v${release.version}.` });
    }

    return (
        <SiteLayout title={`Review ${extension.name} v${release.version}`} section="review">
            <div className="wrap" style={{ paddingTop: 28, paddingBottom: 64 }}>
                <div className="stack-lg">
                    <div className="row wrapflex" style={{ gap: 16, alignItems: 'flex-start' }}>
                        <Link href="/review" className="icon-btn" aria-label="Back to the queue" style={{ border: '1px solid var(--line)' }}>
                            <Icon name="arrowLeft" />
                        </Link>
                        <ExtensionIcon extension={extension} size="lg" />
                        <div className="col grow" style={{ gap: 6, minWidth: 240 }}>
                            <div className="row wrapflex" style={{ gap: 10 }}>
                                <h1 className="h2">
                                    {extension.name}{' '}
                                    <span className="mono muted" style={{ fontWeight: 500 }}>
                                        v{release.version}
                                    </span>
                                </h1>
                                {release.status === 'in_review' && claim?.by?.name ? (
                                    <StatusChip tone="wait" icon="eye" data-status="in_review">
                                        {mine ? 'In review · you' : `In review · ${claim.by.name}`}
                                    </StatusChip>
                                ) : (
                                    <ReleaseStatus status={release.status} />
                                )}
                                {suspended && (
                                    <StatusChip tone="fail" icon="ban">
                                        Listing suspended
                                    </StatusChip>
                                )}
                            </div>
                            <div className="row wrapflex small muted" style={{ gap: 14 }}>
                                {organization && (
                                    <span className="row" style={{ gap: 8 }}>
                                        <Link href={`/publishers/${organization.slug}`}>{organization.name}</Link>
                                        {organization.is_verified ? <Verified short /> : <span>· not verified</span>}
                                    </span>
                                )}
                                <span>{isFirstRelease(release) ? 'First release' : 'Update'}</span>
                                <span>{isPaid(extension.price) ? `${priceLabel(extension.price)} one-time` : 'Free'}</span>
                                {release.channel && release.channel !== 'stable' && <span>{release.channel} channel</span>}
                                {submittedAt ? <span title={dateTime(submittedAt)}>Submitted {timeAgo(submittedAt)}</span> : <span>Uploaded {timeAgo(release.created_at)}</span>}
                            </div>
                        </div>
                        <div className="row wrapflex">
                            {release.status === 'submitted' && (
                                <button type="button" className="btn" aria-disabled={busy} onClick={startReview} data-claim>
                                    <Icon name="eye" /> Start review
                                </button>
                            )}
                            {release.status === 'approved' && (
                                <button type="button" className="btn btn-primary" onClick={() => setDialog('publish')}>
                                    <Icon name="globe" /> Publish
                                </button>
                            )}
                            {extension.slug &&
                                (suspended ? (
                                    <button type="button" className="btn" onClick={() => setDialog('lift')} data-lift>
                                        Lift suspension
                                    </button>
                                ) : (
                                    <button type="button" className="btn btn-danger" onClick={() => setDialog('suspend')} data-suspend>
                                        <Icon name="ban" /> Suspend listing
                                    </button>
                                ))}
                        </div>
                    </div>

                    {suspended && (
                        <Note tone="fail" icon="ban">
                            <strong>This listing is suspended</strong>
                            <span className="small" style={{ display: 'block' }}>
                                {[suspension?.at && `Since ${dateTime(suspension.at)}`, suspension?.by_name && `by ${suspension.by_name}`].filter(Boolean).join(' ')}
                                {suspension?.reason ? `: “${suspension.reason}”` : ''} It can’t be published until the suspension is lifted.
                            </span>
                        </Note>
                    )}

                    {reasons.length > 0 && (
                        <Note tone="info" icon="shield" data-reasons>
                            <span className="small">
                                <strong>Why a person reviews this:</strong> {reasons.length === 1 ? reasons[0] : null}
                            </span>
                            {reasons.length > 1 && (
                                <ul className="small" style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                                    {reasons.map((reason) => (
                                        <li key={reason}>{reason}</li>
                                    ))}
                                </ul>
                            )}
                        </Note>
                    )}

                    <div className="split">
                        <div className="main" style={{ gap: 16 }}>
                            <Segmented options={TABS} value={tab} onChange={setTab} label="Evidence" className="self-start" />
                            <div role="tabpanel" aria-label={TABS.find((t) => t.value === tab)?.label} className="stack">
                                {tab === 'checks' && <ChecksTab release={release} />}
                                {tab === 'manifest' && <ManifestTab release={release} />}
                                {tab === 'listing' && <ListingTab extension={extension} />}
                                {tab === 'timeline' && (
                                    <section className="card card-pad" aria-label="Timeline">
                                        <Timeline entries={release.reviews ?? []} itemLabels={itemLabels} />
                                    </section>
                                )}
                            </div>
                        </div>

                        <aside className="rail rail-first" style={{ flexBasis: 400 }} aria-label="Decision">
                            {decidable ? <DecisionCard decision={decision} /> : <DecidedCard release={release} itemLabels={itemLabels} onPublish={() => setDialog('publish')} />}
                        </aside>
                    </div>
                </div>
            </div>

            {decidable && <DecisionBar decision={decision} />}
            {decidable && <DecisionDialogs decision={decision} />}
            <PublishDialog open={dialog === 'publish'} release={release} onClose={() => setDialog(null)} />
            {extension.slug && <SuspendDialog open={dialog === 'suspend'} extension={extension} onClose={() => setDialog(null)} />}
            {extension.slug && <LiftDialog open={dialog === 'lift'} extension={extension} onClose={() => setDialog(null)} />}
        </SiteLayout>
    );
}

function ChecksTab({ release }) {
    const files = release.files ?? {};
    const archives = ARCHIVES.filter(([kind]) => files[kind]);
    return (
        <>
            <section className="card" aria-label="Automated checks">
                <div className="card-body">
                    <Checks release={release} />
                </div>
            </section>
            <section className="card card-pad stack" aria-labelledby="archives-title">
                <h2 id="archives-title" className="h4">
                    Archives
                </h2>
                {archives.length ? (
                    <div className="grid3" style={{ gap: 10 }}>
                        {archives.map(([kind, label]) => {
                            const file = files[kind];
                            const filename = file.original_filename || file.name || '';
                            const ext = filename.match(/\.(tgz|tar\.gz|tar|zip)$/i)?.[0] ?? '';
                            return (
                                <a
                                    key={kind}
                                    href={`/v1/admin/releases/${release.id}/files/${kind}`}
                                    className="row hover:border-accent-line"
                                    style={{ gap: 10, padding: '12px 14px', minHeight: 44, borderRadius: 12, border: '1px solid var(--line)', color: 'var(--ink)', textDecoration: 'none', background: 'var(--surface-2)' }}
                                    data-archive={kind}
                                >
                                    <Icon name="download" />
                                    <span className="col" style={{ gap: 0, minWidth: 0 }}>
                                        <span className="small" style={{ fontWeight: 600 }}>
                                            {label}
                                            <span className="sr-only"> archive, download</span>
                                        </span>
                                        <span className="xs muted">{[ext, bytes(file.size)].filter(Boolean).join(' · ')}</span>
                                    </span>
                                </a>
                            );
                        })}
                    </div>
                ) : (
                    <p className="small muted">This release has no stored archives.</p>
                )}
                <span className="xs muted">Every download is logged against your account.</span>
            </section>
        </>
    );
}

function ManifestTab({ release }) {
    const manifest = release.manifest ?? {};
    if (!Object.keys(manifest).length) {
        return <p className="small muted">No manifest was read from this release.</p>;
    }
    return (
        <pre className="pre" aria-label="extension.json" tabIndex={0}>
            {JSON.stringify(manifest, null, 2)}
        </pre>
    );
}

function ListingTab({ extension }) {
    const links = [
        ['Website', extension.website_url],
        ['Repository', extension.repo_url],
        ['Support', extension.support_url],
        ['Privacy', extension.privacy_url],
        ['Terms', extension.tos_url],
    ].filter(([, url]) => url);
    const screenshots = extension.screenshots ?? [];

    return (
        <section className="card card-pad stack" aria-label="Listing preview" data-listing-preview>
            <div className="row" style={{ gap: 14 }}>
                <ExtensionIcon extension={extension} size="lg" />
                <div className="col" style={{ gap: 2, minWidth: 0 }}>
                    <span className="h3">{extension.name}</span>
                    {extension.subtitle && <span className="ink2">{extension.subtitle}</span>}
                </div>
            </div>
            {(extension.tags ?? []).length > 0 && (
                <div className="tagline">
                    {extension.tags.map((tag) => (
                        <span key={tag} className="tag">
                            {tag}
                        </span>
                    ))}
                </div>
            )}
            {extension.promotional_text && <p className="ink2">{extension.promotional_text}</p>}
            {extension.description ? <SafeHtml html={extension.description} className="ink2" /> : <p className="small muted">No overview yet.</p>}
            {screenshots.length > 0 && (
                <div className="grid3" style={{ gap: 10 }}>
                    {screenshots.map((shot) => (
                        <figure key={shot.id} style={{ margin: 0 }}>
                            <img src={shot.url} alt={shot.caption || `${extension.name} screenshot`} loading="lazy" style={{ width: '100%', aspectRatio: '16 / 10', objectFit: 'cover', borderRadius: 10, border: '1px solid var(--line)' }} />
                            {shot.caption && <figcaption className="xs muted" style={{ marginTop: 4 }}>{shot.caption}</figcaption>}
                        </figure>
                    ))}
                </div>
            )}
            <div className="grid2 small" style={{ gap: 8 }}>
                {extension.category && <span>Category: {extension.category.name}</span>}
                {extension.hosting && <span>Runs on: {HOSTINGS[extension.hosting] ?? extension.hosting}</span>}
                <span>Price: {priceLabel(extension.price)}</span>
                {links.map(([label, url]) => (
                    <span key={label} style={{ overflowWrap: 'anywhere' }}>
                        {label}:{' '}
                        <a href={url} target="_blank" rel="noopener noreferrer nofollow">
                            {url.replace(/^https?:\/\//, '')}
                        </a>
                    </span>
                ))}
                {extension.copyright && <span>{extension.copyright}</span>}
            </div>
        </section>
    );
}
