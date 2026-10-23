import { useEffect, useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Icon from '../../components/Icon';
import { checksSummary } from '../../components/Checks';
import { ListingStatus, ReleaseStatus, StatusChip } from '../../components/Status';
import { Field, Note } from '../../components/Ui';
import { useToast } from '../../components/Toasts';
import { StudioHeader } from '../../components/studio/StudioChrome';
import { readiness } from '../../components/studio/readiness';
import TagsInput from '../../components/studio/TagsInput';
import OverviewEditor from '../../components/studio/OverviewEditor';
import MediaSection from '../../components/studio/MediaSection';
import PricingSection from '../../components/studio/PricingSection';
import { api } from '../../lib/api';
import { HOSTINGS, openRelease } from '../../lib/statuses';

/** The listing fields the editor saves with `PATCH /v1/publisher/extensions/{slug}`. */
export const LISTING_FIELDS = ['name', 'subtitle', 'description', 'promotional_text', 'category', 'hosting', 'tags', 'website_url', 'support_url', 'privacy_url', 'repo_url', 'tos_url', 'copyright', 'fa_icon'];

export function toDraft(extension) {
    const draft = {};
    for (const field of LISTING_FIELDS) {
        draft[field] = field === 'category' ? (extension.category?.slug ?? '') : field === 'tags' ? [...(extension.tags ?? [])] : (extension[field] ?? '');
    }
    return draft;
}

/** Only what changed, with empty strings sent as null. */
export function changes(draft, saved) {
    const payload = {};
    for (const field of LISTING_FIELDS) {
        if (JSON.stringify(draft[field]) !== JSON.stringify(saved[field])) {
            payload[field] = draft[field] === '' && field !== 'name' ? null : draft[field];
        }
    }
    return payload;
}

const SECTIONS = [
    { id: 's-basics', label: 'Basics' },
    { id: 's-media', label: 'Icon and screenshots' },
    { id: 's-overview', label: 'Overview' },
    { id: 's-links', label: 'Links and legal' },
    { id: 's-pricing', label: 'Pricing' },
];

/**
 * The listing editor: one scrolling document (basics, media, overview, links, pricing) with an
 * anchor nav, an unsaved-changes bar and a "Ready for review?" rail that recomputes from the
 * unsaved draft. Listing edits never need review.
 */
export default function StudioEditor({ extension, releases = [], categories = [] }) {
    const toast = useToast();
    const [saved, setSaved] = useState(() => toDraft(extension));
    const [draft, setDraft] = useState(saved);
    const [resetKey, setResetKey] = useState(0);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});
    const [current, setCurrent] = useState('s-basics');

    const pending = useMemo(() => changes(draft, saved), [draft, saved]);
    const dirty = Object.keys(pending).length > 0;
    const ready = readiness({ ...draft, icon_url: extension.icon_url, status: extension.status }, releases);
    const open = openRelease(releases);
    const missingLinks = ready.items.filter((item) => item.section === 's-links' && !item.ok).length;
    const set = (field) => (value) => setDraft((d) => ({ ...d, [field]: value }));
    const bind = (field) => ({ value: draft[field] ?? '', onChange: (e) => set(field)(e.target.value) });
    const error = (field) => errors[field]?.[0] ?? null;

    useEffect(() => {
        if (!dirty) {
            return undefined;
        }
        const onUnload = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };
        const off = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.only?.length || visit.method !== 'get') {
                return true;
            }
            return window.confirm('Leave without saving? Your listing changes will be lost.');
        });
        window.addEventListener('beforeunload', onUnload);
        return () => {
            off();
            window.removeEventListener('beforeunload', onUnload);
        };
    }, [dirty]);

    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') {
            return undefined;
        }
        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
                if (visible) {
                    setCurrent(visible.target.id);
                }
            },
            { rootMargin: '-10% 0px -60% 0px' }
        );
        SECTIONS.forEach((section) => {
            const node = document.getElementById(section.id);
            if (node) observer.observe(node);
        });
        return () => observer.disconnect();
    }, []);

    async function save() {
        setBusy(true);
        setErrors({});
        try {
            const updated = await api.patch(`/publisher/extensions/${extension.slug}`, pending);
            const next = toDraft(updated);
            setSaved(next);
            setDraft(next);
            router.reload({ only: ['extension'] });
            toast('Listing saved. Changes are live.');
        } catch (e) {
            setErrors(e.fields ?? {});
            toast(Object.keys(e.fields ?? {}).length ? 'Some fields need attention.' : e.message, { type: 'error' });
        } finally {
            setBusy(false);
        }
    }

    function discard() {
        setDraft(saved);
        setErrors({});
        setResetKey((key) => key + 1);
    }

    const npm = extension.packages?.find((p) => p.kind === 'npm')?.name;
    const composer = extension.packages?.find((p) => p.kind === 'composer')?.name;
    const releaseHref = open ? `/studio/${extension.slug}/releases/${open.id}` : `/studio/${extension.slug}/releases`;
    const sectionLinks = [
        ...SECTIONS.map((s) => ({ href: `#${s.id}`, label: s.label, count: s.id === 's-links' && missingLinks ? missingLinks : null, current: current === s.id })),
    ];
    const pageLinks = [
        { href: `/studio/${extension.slug}/releases`, label: 'Releases and checks' },
        { href: `/studio/${extension.slug}/review`, label: 'Review' },
        { href: `/studio/${extension.slug}/history`, label: 'History' },
    ];

    return (
        <SiteLayout title={`${extension.name} · Studio`} section="studio">
            <div className="wrap" style={{ paddingTop: 28, paddingBottom: 96 }}>
                <div style={{ marginBottom: 24 }}>
                    <StudioHeader extension={extension} title={extension.name} sub={<span className="mono">{[npm ?? composer ?? extension.slug, extension.status].join(' · ')}</span>}>
                        <ListingStatus status={extension.status} />
                        {extension.status === 'published' && (
                            <Link href={`/extensions/${extension.slug}`} className="btn btn-sm">
                                View public page
                            </Link>
                        )}
                    </StudioHeader>
                </div>

                <nav className="sd-chips-scroll" aria-label="Sections" style={{ marginBottom: 16 }}>
                    {sectionLinks.map((link) => (
                        <a key={link.href} href={link.href} className="chip">
                            {link.label}
                            {link.count ? <span className="count">{link.count}</span> : null}
                        </a>
                    ))}
                    {pageLinks.map((link) => (
                        <Link key={link.href} href={link.href} className="chip">
                            {link.label}
                        </Link>
                    ))}
                </nav>

                <div className="sd-ed">
                    <nav className="sd-ed-nav anchors" aria-label="Editor sections">
                        <span className="eyebrow" style={{ padding: '0 12px 8px' }}>
                            Listing
                        </span>
                        {sectionLinks.map((link) => (
                            <a key={link.href} href={link.href} aria-current={link.current ? 'true' : undefined}>
                                {link.label}
                                {link.count ? (
                                    <span className="count" aria-label={`${link.count} missing`}>
                                        {link.count}
                                    </span>
                                ) : null}
                            </a>
                        ))}
                        <span className="eyebrow" style={{ padding: '18px 12px 8px' }}>
                            Releases
                        </span>
                        {pageLinks.map((link) => (
                            <Link key={link.href} href={link.href}>
                                {link.label}
                            </Link>
                        ))}
                    </nav>

                    <div className="stack-lg" style={{ minWidth: 0 }}>
                        {dirty && (
                            <div className="sd-savebar" role="region" aria-label="Unsaved changes" data-savebar>
                                <Icon name="pencil" />
                                <span className="grow small" role="status">
                                    Unsaved changes. Listing edits go live on save; they never need review.
                                </span>
                                <button type="button" className="btn btn-sm btn-quiet" onClick={discard} disabled={busy}>
                                    Discard
                                </button>
                                <button type="button" className="btn btn-sm btn-primary" onClick={save} disabled={busy} aria-busy={busy || undefined}>
                                    {busy ? 'Saving…' : 'Save'}
                                </button>
                            </div>
                        )}

                        <section id="s-basics" className="sd-sec card" aria-labelledby="h-basics">
                            <div className="card-head">
                                <h2 id="h-basics" className="h3">
                                    Basics
                                </h2>
                            </div>
                            <div className="card-body stack">
                                <div className="grid2">
                                    <Field label="Name" error={error('name')}>
                                        {(id, aria) => <input id={id} {...aria} className="input" maxLength={120} {...bind('name')} />}
                                    </Field>
                                    <Field label="Subtitle" error={error('subtitle')}>
                                        {(id, aria) => <input id={id} {...aria} className="input" maxLength={255} placeholder="One line under the name" {...bind('subtitle')} />}
                                    </Field>
                                </div>
                                <Field label="Short description" help={`Shown on cards and in search. ${[...(draft.description ?? '')].length} characters; more than 12 needed.`} error={error('description')}>
                                    {(id, aria) => <textarea id={id} {...aria} className="textarea" style={{ minHeight: 72 }} {...bind('description')} />}
                                </Field>
                                <div className="grid2">
                                    <Field label="Category" error={error('category')}>
                                        {(id, aria) => (
                                            <select id={id} {...aria} className="select" {...bind('category')}>
                                                <option value="">Choose a category</option>
                                                {categories.map((c) => (
                                                    <option key={c.slug} value={c.slug}>
                                                        {c.name}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </Field>
                                    <Field label="Hosting" error={error('hosting')}>
                                        {(id, aria) => (
                                            <select id={id} {...aria} className="select" {...bind('hosting')}>
                                                {Object.entries(HOSTINGS).map(([value, label]) => (
                                                    <option key={value} value={value}>
                                                        {label}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </Field>
                                </div>
                                <Field label="Tags" help="Enter or a comma adds a tag; Backspace removes the last. Up to 20." error={error('tags') ?? error('tags.0')}>
                                    {(id, aria) => <TagsInput id={id} describedBy={aria['aria-describedby']} value={draft.tags} onChange={set('tags')} />}
                                </Field>
                            </div>
                        </section>

                        <MediaSection extension={extension} faIcon={draft.fa_icon} onFaIcon={set('fa_icon')} faIconError={error('fa_icon')} />

                        <section id="s-overview" className="sd-sec card" aria-labelledby="h-over">
                            <div className="card-head">
                                <h2 id="h-over" className="h3">
                                    Overview
                                </h2>
                                <span className="small muted">Rendered on your extension page</span>
                            </div>
                            <OverviewEditor key={resetKey} initial={saved.promotional_text} onChange={set('promotional_text')} labelledBy="h-over" />
                            {error('promotional_text') && (
                                <div className="card-body">
                                    <span className="err">{error('promotional_text')}</span>
                                </div>
                            )}
                        </section>

                        <section id="s-links" className="sd-sec card" aria-labelledby="h-links">
                            <div className="card-head">
                                <h2 id="h-links" className="h3">
                                    Links and legal
                                </h2>
                                {missingLinks > 0 && (
                                    <StatusChip tone="you" icon="alert">
                                        {missingLinks} to fill
                                    </StatusChip>
                                )}
                            </div>
                            <div className="card-body grid2">
                                <LinkField label="Website" field="website_url" draft={draft} bind={bind} error={error} ready={ready} need="Needed for review." />
                                <LinkField label="Support URL" field="support_url" draft={draft} bind={bind} error={error} ready={ready} need="Needed for review: where operators report problems." />
                                <LinkField label="Privacy policy URL" field="privacy_url" draft={draft} bind={bind} error={error} ready={ready} need="Needed for review." />
                                <Field label="Repository" optional error={error('repo_url')}>
                                    {(id, aria) => <input id={id} {...aria} className="input" type="url" placeholder="https://github.com/…" {...bind('repo_url')} />}
                                </Field>
                                <Field label="Terms of service" optional error={error('tos_url')}>
                                    {(id, aria) => <input id={id} {...aria} className="input" type="url" placeholder="https://" {...bind('tos_url')} />}
                                </Field>
                                <Field label="Copyright" error={error('copyright')} help={!ready.items.find((i) => i.key === 'copyright').ok ? 'Needed for review.' : undefined}>
                                    {(id, aria) => <input id={id} {...aria} className="input" placeholder={`© ${new Date().getFullYear()} ${extension.organization?.name ?? ''}`.trim()} maxLength={255} {...bind('copyright')} />}
                                </Field>
                            </div>
                        </section>

                        <PricingSection extension={extension} />
                    </div>

                    <aside className="sd-ed-rail stack" aria-label="Ready for review">
                        <ReadinessCard extension={extension} ready={ready} dirty={dirty} releaseHref={releaseHref} />
                        {open && (
                            <section className="card card-pad stack" data-open-release>
                                <h2 className="h4">Release in progress</h2>
                                <div className="row wrapflex" style={{ gap: 10 }}>
                                    <span className="mono">v{open.version}</span>
                                    <ReleaseStatus status={open.status} />
                                </div>
                                <span className="small muted">Checks: {checksSummary(open).label}</span>
                                <Link href={releaseHref} className="small">
                                    Releases and checks
                                </Link>
                            </section>
                        )}
                    </aside>
                </div>
            </div>
        </SiteLayout>
    );
}

function LinkField({ label, field, bind, error, ready, need }) {
    const missing = !ready.items.find((item) => item.key === field)?.ok;
    return (
        <Field label={label} error={error(field) ?? (missing ? need : null)}>
            {(id, aria) => <input id={id} {...aria} className={`input ${missing ? 'is-error' : ''}`} type="url" placeholder="https://" maxLength={255} {...bind(field)} />}
        </Field>
    );
}

function ReadinessCard({ extension, ready, dirty, releaseHref }) {
    const published = extension.status === 'published';
    const label = ready.ready ? 'Ready' : `${ready.done} of ${ready.total}`;
    const version = ready.release?.version;

    return (
        <section className="card" style={{ boxShadow: 'var(--e2)' }} aria-labelledby="h-ready" data-readiness>
            <div className="card-body stack" style={{ gap: 14 }}>
                <div className="row between">
                    <h2 id="h-ready" className="h4">
                        Ready for review?
                    </h2>
                    <StatusChip tone={ready.ready ? 'ok' : 'you'} icon={ready.ready ? 'check' : 'alert'} data-ready={ready.ready}>
                        {label}
                    </StatusChip>
                </div>
                <div className="meter" role="progressbar" aria-valuemin={0} aria-valuemax={ready.total} aria-valuenow={ready.done} aria-label="Readiness">
                    <span style={{ width: `${Math.round((ready.done / ready.total) * 100)}%` }} />
                </div>
                {published && <p className="xs muted">Published listings are not re-checked; listing edits go live on save.</p>}
                <ul className="col" style={{ gap: 0, listStyle: 'none', margin: 0, padding: 0 }}>
                    {ready.items.map((item) => (
                        <li key={item.key} className={`sd-rd ${item.ok ? 'ok' : 'no'}`} data-item={item.key} data-ok={item.ok}>
                            <span className="d" aria-hidden="true">
                                <Icon name={item.ok ? 'check' : 'alert'} size={12} strokeWidth={2.6} />
                            </span>
                            <span className="t grow">
                                {item.label}
                                <span className="sr-only">{item.ok ? ', done' : ', missing'}</span>
                            </span>
                            {!item.ok &&
                                (item.section ? (
                                    <a href={`#${item.section}`} className="small" aria-label={`Fix: ${item.label}`}>
                                        Fix
                                    </a>
                                ) : (
                                    <Link href={item.href === 'release' ? releaseHref : `/studio/${extension.slug}/releases`} className="small" aria-label={`Fix: ${item.label}`}>
                                        {ready.release ? 'Open' : 'Upload'}
                                    </Link>
                                ))}
                        </li>
                    ))}
                </ul>
                {ready.ready && !dirty ? (
                    <Link href={`/studio/${extension.slug}/review`} className="btn btn-primary">
                        Submit{version ? ` v${version}` : ''} for review
                    </Link>
                ) : (
                    <button type="button" className="btn btn-primary" disabled>
                        Submit{version ? ` v${version}` : ''} for review
                    </button>
                )}
                {dirty ? (
                    <Note tone="you" className="small">
                        Save your changes first; the review sees the saved listing.
                    </Note>
                ) : (
                    <span className="xs muted">Mirrors the registry’s readiness rules and updates as you type.</span>
                )}
            </div>
        </section>
    );
}
