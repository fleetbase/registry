import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ExtensionIcon from '../../components/ExtensionIcon';
import CopyCommand from '../../components/CopyCommand';
import Icon from '../../components/Icon';
import { StatusChip } from '../../components/Status';
import { Reviewed, Verified } from '../../components/Trust';
import { Note, SafeHtml, Segmented } from '../../components/Ui';
import { bytes, initials, isPaid, money, priceLabel, shortDate } from '../../lib/format';
import { HOSTINGS } from '../../lib/statuses';

/**
 * An extension: who built it, screenshots, overview, versions, details, and an install panel that
 * adapts to free or paid and cloud or self-hosted, with a compatibility check against any console
 * version (a partial reload of `compatibility`).
 */
export default function ExtensionShow({ extension, releases, compatibility }) {
    const { registry, auth } = usePage().props;
    const selfHosted = extension.hosting === 'self_hosted';
    const paid = isPaid(extension.price);
    const [method, setMethod] = useState(selfHosted ? 'cli' : 'console');
    const [lightbox, setLightbox] = useState(null);
    const [check, setCheck] = useState(compatibility?.version ?? '');
    const screenshots = extension.screenshots ?? [];
    const latest = releases.find((release) => release.status === 'published') ?? null;
    const owns = auth?.account?.organizations?.some((organization) => organization.id === extension.publisher?.id);
    const scope = extension.packages?.npm?.startsWith('@') ? extension.packages.npm.split('/')[0] : null;

    function checkVersion(event) {
        event.preventDefault();
        router.reload({ only: ['compatibility'], data: { check: check.trim() } });
    }

    const links = [
        ['Website', extension.website_url],
        ['Repository', extension.repo_url],
        ['Support', extension.support_url],
    ].filter(([, url]) => url);
    const legal = [
        ['Privacy policy', extension.privacy_url],
        ['Terms', extension.tos_url],
    ].filter(([, url]) => url);

    return (
        <SiteLayout title={extension.name}>
            <div className="wrap" style={{ paddingTop: 28, paddingBottom: 80 }}>
                <nav className="small muted row" aria-label="Breadcrumb" style={{ gap: 8, marginBottom: 24 }}>
                    <Link href="/">Discover</Link>
                    {extension.category && (
                        <>
                            <span aria-hidden="true">/</span>
                            <Link href={`/?category=${extension.category.slug}`}>{extension.category.name}</Link>
                        </>
                    )}
                    <span aria-hidden="true">/</span>
                    <span aria-current="page" className="ink2">
                        {extension.name}
                    </span>
                </nav>

                <div className="row wrapflex" style={{ gap: 24, alignItems: 'flex-start', marginBottom: 32 }}>
                    <ExtensionIcon extension={extension} size="xl" />
                    <div className="col grow" style={{ gap: 10, minWidth: 260 }}>
                        <div className="row wrapflex" style={{ gap: 12 }}>
                            <h1 className="h1" data-extension-name>
                                {extension.name}
                            </h1>
                            {latest?.channel === 'beta' && <StatusChip tone="you">Beta</StatusChip>}
                        </div>
                        {extension.subtitle && <p className="lead">{extension.subtitle}</p>}
                        <div className="row wrapflex" style={{ gap: 18 }}>
                            {extension.publisher && (
                                <Link href={`/publishers/${extension.publisher.slug}`} className="row" style={{ gap: 8, color: 'var(--ink)' }}>
                                    <span className="avatar" style={{ width: 28, height: 28, fontSize: 11 }} aria-hidden="true">
                                        {initials(extension.publisher.name)}
                                    </span>
                                    <span style={{ fontWeight: 600 }}>{extension.publisher.name}</span>
                                </Link>
                            )}
                            {extension.publisher?.is_verified && <Verified />}
                            {latest && <Reviewed />}
                        </div>
                        <div className="row wrapflex small muted num" style={{ gap: 16 }}>
                            {latest && (
                                <span>
                                    v{latest.version} · {latest.channel}
                                </span>
                            )}
                            {latest && <span>Updated {shortDate(latest.published_at)}</span>}
                            <span>{extension.installs_count} installs</span>
                            <span>{HOSTINGS[extension.hosting] ?? extension.hosting}</span>
                        </div>
                    </div>
                </div>

                <div className="split" style={{ alignItems: 'flex-start' }}>
                    <div className="main">
                        {owns && (
                            <Note tone="info">
                                You publish this extension. <Link href={`/studio/${extension.slug}`}>Open it in Studio</Link>
                            </Note>
                        )}

                        {screenshots.length > 0 && (
                            <section aria-label="Screenshots" style={{ display: 'grid', gridTemplateColumns: screenshots.length > 1 ? 'minmax(0,3fr) minmax(0,1fr)' : 'minmax(0,1fr)', gap: 12 }}>
                                <button type="button" className="card" style={{ padding: 0, overflow: 'hidden', cursor: 'zoom-in', height: 360 }} onClick={() => setLightbox(0)} aria-label={`Open screenshot 1 of ${screenshots.length}`}>
                                    <img src={screenshots[0].url} alt={screenshots[0].caption ?? `${extension.name} screenshot 1`} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                </button>
                                {screenshots.length > 1 && (
                                    <div className="hide-sm" style={{ display: 'grid', gridTemplateRows: 'repeat(3, 1fr)', gap: 12, height: 360 }}>
                                        {screenshots.slice(1, 4).map((shot, index) => (
                                            <button key={shot.id} type="button" className="card" style={{ padding: 0, overflow: 'hidden', cursor: 'zoom-in', position: 'relative' }} onClick={() => setLightbox(index + 1)} aria-label={`Open screenshot ${index + 2} of ${screenshots.length}`}>
                                                <img src={shot.url} alt={shot.caption ?? `${extension.name} screenshot ${index + 2}`} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                                                {index === 2 && screenshots.length > 4 && (
                                                    <span className="scrim" style={{ position: 'absolute', inset: 0, display: 'grid', placeItems: 'center', color: '#fff', fontWeight: 600 }}>
                                                        +{screenshots.length - 4} more
                                                    </span>
                                                )}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </section>
                        )}

                        <section className="card card-pad stack" aria-labelledby="h-over">
                            <h2 id="h-over" className="h3">
                                Overview
                            </h2>
                            <SafeHtml html={extension.promotional_text || extension.description} className="ink2" />
                            {extension.tags?.length > 0 && (
                                <div className="tagline">
                                    {extension.tags.map((tag) => (
                                        <Link key={tag} href={`/?q=${encodeURIComponent(tag)}`} className="tag">
                                            {tag}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </section>

                        <section className="card" aria-labelledby="h-ver">
                            <div className="card-head">
                                <h2 id="h-ver" className="h3">
                                    Versions
                                </h2>
                                <span className="small muted">{releases.length} releases</span>
                            </div>
                            <div className="card-body tl" data-versions>
                                {releases.length === 0 && <p className="small muted">No published versions.</p>}
                                {releases.map((release, index) => (
                                    <div key={release.id} className="tl-item" data-version={release.version}>
                                        <span className={`tl-dot ${index === 0 && release.status === 'published' ? 'ok' : ''}`}>
                                            <Icon name={release.status === 'yanked' ? 'minus' : index === 0 ? 'check' : 'arrowUp'} strokeWidth={2.4} />
                                        </span>
                                        <div>
                                            <div className="row wrapflex" style={{ gap: 10 }}>
                                                <span className="tl-title mono" style={release.status === 'yanked' ? { textDecoration: 'line-through' } : undefined}>
                                                    v{release.version}
                                                </span>
                                                {index === 0 && release.status === 'published' && <StatusChip tone="ok">Latest</StatusChip>}
                                                {release.channel === 'beta' && <StatusChip tone="you">Beta</StatusChip>}
                                                {release.status === 'yanked' && <StatusChip tone="idle">Yanked</StatusChip>}
                                                <span className="tl-meta">
                                                    {[shortDate(release.published_at), release.size_bytes ? bytes(release.size_bytes) : null, release.compat?.console ? `console ${release.compat.console}` : null].filter(Boolean).join(' · ')}
                                                </span>
                                            </div>
                                            {release.notes ? <SafeHtml html={release.notes} className="quote" /> : release.status === 'yanked' ? <div className="tl-meta">Withdrawn by the publisher. Existing installs keep working.</div> : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="card" aria-labelledby="h-det">
                            <div className="card-head">
                                <h2 id="h-det" className="h3">
                                    Details
                                </h2>
                            </div>
                            <dl className="card-body grid2" style={{ margin: 0, rowGap: 18 }}>
                                {extension.packages?.npm && <Detail label="Engine package (npm)" mono value={extension.packages.npm} />}
                                {extension.packages?.composer && <Detail label="API package (Composer)" mono value={extension.packages.composer} />}
                                {extension.languages?.length > 0 && <Detail label="Languages" value={extension.languages.join(', ')} />}
                                {extension.copyright && <Detail label="Copyright" value={extension.copyright} />}
                                {links.length > 0 && <Detail label="Links" value={<LinkList links={links} />} />}
                                {legal.length > 0 && <Detail label="Legal" value={<LinkList links={legal} />} />}
                            </dl>
                        </section>
                    </div>

                    <aside className="rail rail-first" aria-label="Install">
                        <section className="card" style={{ boxShadow: 'var(--e2)' }}>
                            <div className="card-body stack">
                                <div className="row between">
                                    <div className="col" style={{ gap: 2 }}>
                                        <span className="small muted">{paid ? (extension.price.on_sale ? `One-time licence · was ${money(extension.price.amount, extension.price.currency)}` : 'One-time licence, per organization') : 'Free'}</span>
                                        <span className="h2 num">{priceLabel(extension.price)}</span>
                                    </div>
                                    {extension.price?.on_sale && <StatusChip tone="you">On sale</StatusChip>}
                                </div>
                                <Segmented
                                    label="Install method"
                                    value={method}
                                    onChange={setMethod}
                                    className="w-full"
                                    options={[
                                        { value: 'console', label: 'Console' },
                                        { value: 'cli', label: 'CLI' },
                                        { value: 'packages', label: 'Packages' },
                                    ]}
                                />
                                {method === 'console' &&
                                    (selfHosted ? (
                                        <Note tone="you" icon="terminal">
                                            Self-hosted only. It is not offered on Fleetbase Cloud; install it with the CLI on your own server.
                                        </Note>
                                    ) : (
                                        <div className="col" style={{ gap: 14 }} data-install="console">
                                            <Step n={1}>
                                                In your console, open <strong>Extensions</strong>.
                                            </Step>
                                            <Step n={2}>
                                                Find <strong>{extension.name}</strong>
                                                {paid ? ', choose Buy and pay with Stripe, then Install.' : ' and choose Install.'}
                                            </Step>
                                            <Step n={3}>The console installs the engine and API package and reloads when done.</Step>
                                            {paid && <span className="xs muted">Paid extensions are bought in your console; checkout here opens with payouts.</span>}
                                        </div>
                                    ))}
                                {method === 'cli' && (
                                    <div className="col" style={{ gap: 10 }} data-install="cli">
                                        <span className="small ink2">On the server that runs your Fleetbase instance:</span>
                                        {paid && <CopyCommand value={`flb set-auth flbp_… --registry ${registry.url}`} label="set-auth command" />}
                                        <CopyCommand value={`flb install ${extension.slug}`} label="install command" />
                                        {paid && (
                                            <span className="xs muted">
                                                Paid: use a token with the <strong>install</strong> scope from an organization that bought it. <Link href="/account/tokens">Create a token</Link>
                                            </span>
                                        )}
                                    </div>
                                )}
                                {method === 'packages' && (
                                    <div className="col" style={{ gap: 10 }} data-install="packages">
                                        {extension.packages?.npm && (
                                            <>
                                                <span className="small ink2">
                                                    Engine, in <code>.npmrc</code>:
                                                </span>
                                                <pre className="pre">{[scope ? `${scope}:registry=${registry.url}/npm/` : `registry=${registry.url}/npm/`, paid ? `//${registry.host}/npm/:_authToken=flbp_…` : null].filter(Boolean).join('\n')}</pre>
                                            </>
                                        )}
                                        {extension.packages?.composer && (
                                            <>
                                                <span className="small ink2">API package:</span>
                                                <CopyCommand value={`composer config repositories.fleetbase composer ${registry.url}/composer`} label="Composer repository command" />
                                                <CopyCommand value={`composer require ${extension.packages.composer}${latest ? `:^${latest.version}` : ''}`} label="Composer require command" />
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        </section>

                        <section className="card card-pad stack" aria-labelledby="h-compat" data-compat>
                            {compatibility?.valid && (
                                <span className={`st st-${compatibility.compatible ? 'ok' : 'fail'}`} style={{ alignSelf: 'flex-start' }}>
                                    <Icon name={compatibility.compatible ? 'check' : 'x'} strokeWidth={2.4} />
                                    {compatibility.compatible ? `Works with ${compatibility.version}` : `Not for ${compatibility.version}`}
                                </span>
                            )}
                            <h2 id="h-compat" className="h4">
                                Compatibility
                            </h2>
                            <dl className="col small" style={{ gap: 8, margin: 0 }}>
                                <div className="row between">
                                    <dt className="muted">Console</dt>
                                    <dd className="mono" style={{ margin: 0 }}>
                                        {latest?.compat?.console ?? 'Any version'}
                                    </dd>
                                </div>
                                <div className="row between">
                                    <dt className="muted">API</dt>
                                    <dd className="mono" style={{ margin: 0 }}>
                                        {latest?.compat?.api ?? 'Any version'}
                                    </dd>
                                </div>
                                <div className="row between">
                                    <dt className="muted">Hosting</dt>
                                    <dd style={{ margin: 0 }}>{HOSTINGS[extension.hosting]}</dd>
                                </div>
                            </dl>
                            <form className="field" method="get" onSubmit={checkVersion}>
                                <label className="label" htmlFor="check-version">
                                    Check a console version
                                </label>
                                <div className="row">
                                    <input id="check-version" name="check" className="input mono" placeholder="0.7.69" value={check} onChange={(event) => setCheck(event.target.value)} />
                                    <button type="submit" className="btn btn-sm">
                                        Check
                                    </button>
                                </div>
                                {compatibility && !compatibility.valid && <span className="err">Enter a version like 0.7.69.</span>}
                                {compatibility?.valid && !compatibility.compatible && (
                                    <span className="err">
                                        <Icon name="x" size={14} strokeWidth={2.4} />
                                        {compatibility.newest_compatible ? `v${compatibility.newest_compatible} is the newest release that supports ${compatibility.version}.` : `No release supports ${compatibility.version}.`}
                                    </span>
                                )}
                            </form>
                        </section>

                        {extension.publisher && (
                            <section className="card card-pad stack" aria-labelledby="h-pub">
                                <div className="row">
                                    <span className="avatar" aria-hidden="true">
                                        {initials(extension.publisher.name)}
                                    </span>
                                    <div className="col grow" style={{ gap: 0 }}>
                                        <Link href={`/publishers/${extension.publisher.slug}`} id="h-pub" className="h4" style={{ color: 'var(--ink)' }}>
                                            {extension.publisher.name}
                                        </Link>
                                        <span className="xs muted">
                                            {[extension.publisher.is_verified ? 'Verified' : null, extension.publisher.extensions_count != null ? `${extension.publisher.extensions_count} extensions` : null, extension.publisher.created_at ? `since ${new Date(extension.publisher.created_at).getFullYear()}` : null].filter(Boolean).join(' · ')}
                                        </span>
                                    </div>
                                </div>
                                {extension.publisher.bio && <p className="small ink2">{extension.publisher.bio}</p>}
                                <Link href={`/publishers/${extension.publisher.slug}`} className="small">
                                    All extensions from {extension.publisher.name}
                                </Link>
                            </section>
                        )}
                    </aside>
                </div>
            </div>
            {lightbox !== null && <Lightbox extension={extension} screenshots={screenshots} index={lightbox} onClose={() => setLightbox(null)} />}
        </SiteLayout>
    );
}

function Step({ n, children }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: '28px minmax(0,1fr)', gap: 12, alignItems: 'start' }}>
            <span style={{ width: 28, height: 28, borderRadius: '50%', display: 'grid', placeItems: 'center', background: 'var(--accent-soft)', color: 'var(--accent)', fontWeight: 650, fontSize: 13 }} aria-hidden="true">
                {n}
            </span>
            <span className="small">{children}</span>
        </div>
    );
}

function Detail({ label, value, mono = false }) {
    return (
        <div>
            <dt className="xs muted">{label}</dt>
            <dd className={`small ${mono ? 'mono' : ''}`} style={{ margin: '2px 0 0', wordBreak: 'break-word' }}>
                {value}
            </dd>
        </div>
    );
}

function LinkList({ links }) {
    return (
        <span className="row wrapflex" style={{ gap: 14 }}>
            {links.map(([label, url]) => (
                <a key={label} href={url} target="_blank" rel="noopener noreferrer nofollow">
                    {label}
                </a>
            ))}
        </span>
    );
}

/** Full-size screenshots: arrows move, Escape and the close button close. */
function Lightbox({ extension, screenshots, index: start, onClose }) {
    const [index, setIndex] = useState(start);
    const shot = screenshots[index];
    const count = screenshots.length;

    useEffect(() => {
        function onKey(event) {
            if (event.key === 'Escape') onClose();
            if (event.key === 'ArrowLeft') setIndex((i) => (i - 1 + count) % count);
            if (event.key === 'ArrowRight') setIndex((i) => (i + 1) % count);
        }
        window.addEventListener('keydown', onKey);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            window.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
        };
    }, [count, onClose]);

    const button = { width: 52, height: 52, borderRadius: '50%', border: '1px solid rgba(230,240,251,.18)', background: 'rgba(230,240,251,.08)', color: '#eaf2fc', display: 'grid', placeItems: 'center', cursor: 'pointer' };

    return (
        <div role="dialog" aria-modal="true" aria-label={`Screenshot ${index + 1} of ${count}`} style={{ position: 'fixed', inset: 0, zIndex: 60, background: 'rgba(3,11,20,.94)', display: 'flex', flexDirection: 'column' }}>
            <div className="row between" style={{ padding: '20px 28px', color: '#eaf2fc' }}>
                <div className="row" style={{ gap: 12 }}>
                    <ExtensionIcon extension={extension} size="sm" />
                    <span className="h4">{extension.name}</span>
                    <span className="small" style={{ color: '#93abc8' }}>
                        {index + 1} of {count}
                    </span>
                </div>
                <button type="button" style={button} aria-label="Close (Esc)" onClick={onClose} autoFocus>
                    <Icon name="close" size={20} />
                </button>
            </div>
            <div className="row grow" style={{ padding: '0 28px', gap: 24, justifyContent: 'center', minHeight: 0 }}>
                {count > 1 && (
                    <button type="button" style={button} aria-label="Previous screenshot" onClick={() => setIndex((index - 1 + count) % count)}>
                        <Icon name="arrowLeft" size={20} />
                    </button>
                )}
                <figure style={{ margin: 0, flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14, minHeight: 0 }}>
                    <img src={shot.url} alt={shot.caption ?? `Screenshot ${index + 1}`} style={{ maxWidth: '100%', maxHeight: '72vh', borderRadius: 16, border: '1px solid #1e4a78' }} />
                    {shot.caption && (
                        <figcaption className="small" style={{ color: '#c3d4ea' }}>
                            {shot.caption}
                        </figcaption>
                    )}
                </figure>
                {count > 1 && (
                    <button type="button" style={button} aria-label="Next screenshot" onClick={() => setIndex((index + 1) % count)}>
                        <Icon name="arrowRight" size={20} />
                    </button>
                )}
            </div>
            <div className="row" style={{ justifyContent: 'center', gap: 10, padding: '20px 0 28px' }}>
                {screenshots.map((s, i) => (
                    <button key={s.id} type="button" aria-label={`Screenshot ${i + 1}`} aria-current={i === index} onClick={() => setIndex(i)} style={{ width: 96, height: 60, borderRadius: 8, border: `2px solid ${i === index ? '#61a0e8' : 'transparent'}`, padding: 0, overflow: 'hidden', background: '#0b2848', cursor: 'pointer' }}>
                        <img src={s.url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                    </button>
                ))}
            </div>
        </div>
    );
}
