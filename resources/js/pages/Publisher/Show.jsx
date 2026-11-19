import { usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ExtensionCard from '../../components/ExtensionCard';
import Icon from '../../components/Icon';
import { Verified } from '../../components/Trust';
import { EmptyState, Note } from '../../components/Ui';
import { initials, plural } from '../../lib/format';

function host(url) {
    try {
        return new URL(url).host.replace(/^www\./, '');
    } catch {
        return url;
    }
}

/** A publisher's public profile: who they are, whether Fleetbase verified them, their extensions. */
export default function PublisherShow({ publisher }) {
    const { console: consoleVersion } = usePage().props;
    const extensions = publisher.extensions ?? [];
    const count = publisher.extensions_count ?? extensions.length;
    const since = publisher.created_at ? new Date(publisher.created_at).getFullYear() : null;
    // Every published release passed review; count the listings whose latest release is live.
    const reviewed = extensions.filter((extension) => extension.latest_release?.status === 'published').length;
    const website = publisher.website && /^https?:\/\//i.test(publisher.website) ? publisher.website : null;

    return (
        <SiteLayout title={publisher.name}>
            <section className="hero">
                <div className="wrap row wrapflex" style={{ paddingTop: 56, paddingBottom: 48, gap: 28, alignItems: 'flex-start' }}>
                    {publisher.avatar_url ? (
                        <img src={publisher.avatar_url} alt="" className="avatar" style={{ width: 96, height: 96, borderRadius: 24, objectFit: 'cover' }} />
                    ) : (
                        <span className="avatar" style={{ width: 96, height: 96, fontSize: 32, borderRadius: 24 }} aria-hidden="true">
                            {initials(publisher.name)}
                        </span>
                    )}
                    <div className="col grow" style={{ gap: 12, minWidth: 260 }}>
                        <div className="row wrapflex" style={{ gap: 12 }}>
                            <h1 className="h1" data-publisher-name>
                                {publisher.name}
                            </h1>
                            {publisher.is_verified && <Verified />}
                        </div>
                        {publisher.bio && (
                            <p className="lead" style={{ maxWidth: 680, whiteSpace: 'pre-line' }}>
                                {publisher.bio}
                            </p>
                        )}
                        <div className="row wrapflex small muted" style={{ gap: 18 }}>
                            <span className="mono">@{publisher.slug}</span>
                            <span>{plural(count, 'extension')}</span>
                            {since && <span>Publishing since {since}</span>}
                            {website && (
                                <a href={website} target="_blank" rel="noopener noreferrer nofollow">
                                    {host(website)}
                                </a>
                            )}
                        </div>
                    </div>
                </div>
            </section>

            <div className="wrap" style={{ paddingTop: 40, paddingBottom: 80 }}>
                <div className="split">
                    <div className="main">
                        <h2 className="h3">Extensions</h2>
                        {extensions.length ? (
                            <div className="grid2">
                                {extensions.map((extension) => (
                                    <ExtensionCard key={extension.id ?? extension.slug} extension={extension} console={consoleVersion} />
                                ))}
                            </div>
                        ) : (
                            <EmptyState icon="box" title="Nothing published yet">
                                {publisher.name} has no extensions in the registry yet.
                            </EmptyState>
                        )}
                    </div>
                    <aside className="rail" aria-label="About this publisher">
                        <section className="card card-pad stack" aria-labelledby="h-about">
                            <h2 id="h-about" className="h4">
                                About this publisher
                            </h2>
                            <dl className="col small" style={{ gap: 10, margin: 0 }}>
                                <div className="row between" style={{ gap: 12 }}>
                                    <dt className="muted">Verified</dt>
                                    <dd style={{ margin: 0 }}>
                                        {publisher.is_verified ? (
                                            <span className="row" style={{ gap: 6 }}>
                                                <Icon name="shield" size={14} />
                                                Yes, by Fleetbase
                                            </span>
                                        ) : (
                                            'Not yet'
                                        )}
                                    </dd>
                                </div>
                                <div className="row between" style={{ gap: 12 }}>
                                    <dt className="muted">Package scope</dt>
                                    <dd className="mono" style={{ margin: 0 }}>
                                        @{publisher.slug} · {publisher.slug}/
                                    </dd>
                                </div>
                                {extensions.length > 0 && (
                                    <div className="row between" style={{ gap: 12 }}>
                                        <dt className="muted">Reviewed releases</dt>
                                        <dd style={{ margin: 0 }}>{reviewed === extensions.length ? `All ${reviewed} latest` : `${reviewed} of ${extensions.length} latest`}</dd>
                                    </div>
                                )}
                                {website && (
                                    <div className="row between" style={{ gap: 12 }}>
                                        <dt className="muted">Website</dt>
                                        <dd style={{ margin: 0, minWidth: 0, overflowWrap: 'anywhere', textAlign: 'right' }}>
                                            <a href={website} target="_blank" rel="noopener noreferrer nofollow">
                                                {host(website)}
                                            </a>
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </section>
                        <Note tone="info">
                            <span className="small">
                                Verified means Fleetbase confirmed this organization’s identity. It says nothing about any one release; look for <strong>Reviewed</strong> on each extension.
                            </span>
                        </Note>
                    </aside>
                </div>
            </div>
        </SiteLayout>
    );
}
