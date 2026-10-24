import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ExtensionIcon from '../../components/ExtensionIcon';
import Route from '../../components/Route';
import { Field, Note, Segmented } from '../../components/Ui';
import { publishingOrganizations } from '../../components/studio/StudioChrome';
import { AvailabilityChip, AvailabilityLine, usePackageAvailability } from '../../components/studio/availability';
import { api } from '../../lib/api';
import '../../components/studio/studio.css';

const HOSTING = [
    { value: 'both', label: 'Cloud and self-hosted', help: 'Offered on Fleetbase Cloud and installable on self-hosted servers.' },
    { value: 'cloud', label: 'Cloud only', help: 'Only on Fleetbase Cloud consoles.' },
    { value: 'self_hosted', label: 'Self-hosted', help: 'Installed with the CLI on the operator’s own server.' },
];

/** The slug the registry derives from a name (Laravel's Str::slug for ASCII names). */
export function slugify(name) {
    return String(name ?? '')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function suggestedPackages(orgSlug, name) {
    const slug = slugify(name) || 'my-extension';
    return { npm: `@${orgSlug}/${slug}-engine`, composer: `${orgSlug}/${slug}-api` };
}

/**
 * New extension: name, the organization it is published as, the package names it claims
 * (suggested from the organization's scope), category and hosting, with a live preview card.
 */
export default function StudioCreate({ categories = [] }) {
    const { auth } = usePage().props;
    const organizations = publishingOrganizations(auth?.account);
    const [name, setName] = useState('');
    const [org, setOrg] = useState(() => (organizations.find((o) => !o.is_personal) ?? organizations[0])?.slug ?? null);
    const [category, setCategory] = useState('');
    const [hosting, setHosting] = useState('both');
    const [editing, setEditing] = useState(false);
    const [names, setNames] = useState({ npm: '', composer: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const organization = organizations.find((o) => o.slug === org) ?? null;
    const suggested = suggestedPackages(org ?? 'your-org', name);
    const packages = editing ? names : suggested;
    const categoryName = categories.find((c) => c.slug === category)?.name;
    const tooShort = name.trim().length < 2;
    // Suggested names follow the extension name, so check them once there is one.
    const availability = usePackageAvailability(organization?.slug, packages, { enabled: editing || !tooShort });

    function startEditing() {
        setNames(suggested);
        setEditing(true);
    }

    async function submit(event) {
        event.preventDefault();
        if (!organization || tooShort || busy) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const created = await api.post('/publisher/extensions', {
                name: name.trim(),
                organization: organization.slug,
                packages: { npm: packages.npm.trim() || undefined, composer: packages.composer.trim() || undefined },
                category: category || undefined,
                hosting,
            });
            router.visit(`/studio/${created.slug}`);
        } catch (e) {
            setError(e);
            if (e.field?.('packages.npm') || e.field?.('packages.composer')) {
                setNames(packages);
                setEditing(true);
            }
            setBusy(false);
        }
    }

    const fieldError = (key) => error?.field?.(key) ?? null;
    const general = error && !Object.keys(error.fields ?? {}).length ? error.message : null;

    return (
        <SiteLayout title="New extension" section="studio">
            <div className="wrap" style={{ paddingTop: 40, paddingBottom: 80 }}>
                <div className="split">
                    <form method="post" className="main" aria-labelledby="h-create" onSubmit={submit} noValidate>
                        <div className="col" style={{ gap: 8 }}>
                            <span className="eyebrow">New extension · 1 of 3</span>
                            <h1 id="h-create" className="h1">
                                Name it and claim its packages
                            </h1>
                            <p className="ink2">This creates a draft only your organization sees. Copy, screenshots and pricing come next, in the editor.</p>
                        </div>

                        {organizations.length === 0 && (
                            <Note tone="you">
                                You can publish only as an organization where you are an owner, admin or publisher, and you have none yet. <Link href="/account/organizations">Create an organization</Link> or ask an owner to add you as a publisher.
                            </Note>
                        )}
                        {general && (
                            <Note tone="fail" role="alert">
                                {general}
                            </Note>
                        )}

                        <Field label="Extension name" help="The public name. At least 4 characters." error={fieldError('name') ?? fieldError('slug')}>
                            {(id, aria) => <input id={id} {...aria} className="input" style={{ fontSize: 18, minHeight: 52 }} value={name} onChange={(e) => setName(e.target.value)} autoComplete="off" maxLength={120} required />}
                        </Field>

                        {organizations.length > 0 && (
                            <fieldset style={{ border: 0, padding: 0, margin: 0 }} className="stack">
                                <legend className="label" style={{ marginBottom: 4 }}>
                                    Publish as
                                </legend>
                                <div className="grid2" role="radiogroup" aria-label="Publish as" style={{ gap: 10 }}>
                                    {organizations.map((o) => (
                                        <button key={o.id} type="button" className="sd-opt-card" role="radio" aria-checked={o.slug === org} onClick={() => setOrg(o.slug)}>
                                            <span className="sd-radio" aria-hidden="true" />
                                            <span className="col" style={{ gap: 2 }}>
                                                <span className="h4">{o.name}</span>
                                                <span className="small muted">
                                                    {o.is_personal ? 'Personal' : o.role.charAt(0).toUpperCase() + o.role.slice(1)} · @{o.slug}
                                                </span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                {fieldError('organization') && <span className="err">{fieldError('organization')}</span>}
                            </fieldset>
                        )}

                        <div className="stack">
                            <span className="label" id="pkg-label">
                                Packages
                            </span>
                            {editing ? (
                                <div className="grid2">
                                    <div className="stack" style={{ gap: 8 }}>
                                        <Field label="npm engine" error={fieldError('packages.npm')}>
                                            {(id, aria) => <input id={id} {...aria} className="input mono" value={names.npm} onChange={(e) => setNames({ ...names, npm: e.target.value })} spellCheck={false} autoComplete="off" />}
                                        </Field>
                                        <AvailabilityLine result={availability.npm} data-availability-for="npm" />
                                    </div>
                                    <div className="stack" style={{ gap: 8 }}>
                                        <Field label="Composer API" error={fieldError('packages.composer')}>
                                            {(id, aria) => <input id={id} {...aria} className="input mono" value={names.composer} onChange={(e) => setNames({ ...names, composer: e.target.value })} spellCheck={false} autoComplete="off" />}
                                        </Field>
                                        <AvailabilityLine result={availability.composer} data-availability-for="composer" />
                                    </div>
                                </div>
                            ) : (
                                <div className="stack" aria-labelledby="pkg-label" data-packages>
                                    {[
                                        ['npm', 'npm engine'],
                                        ['composer', 'Composer API'],
                                    ].map(([kind, label]) => (
                                        <div key={kind} className="col" style={{ gap: 4 }}>
                                            <div className="sd-pkg">
                                                <span className="k">{label}</span>
                                                <span className="grow" data-package={kind}>
                                                    {suggested[kind]}
                                                </span>
                                                <span aria-live="polite" data-availability-for={kind}>
                                                    <AvailabilityChip result={availability[kind]} />
                                                    {availability[kind].state === 'taken' && availability[kind].reason && <span className="sr-only">{availability[kind].reason}</span>}
                                                </span>
                                            </div>
                                            {availability[kind].state === 'taken' && availability[kind].reason && (
                                                <span className="small ink2" aria-hidden="true" data-availability-reason={kind}>
                                                    {availability[kind].reason}
                                                </span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                            <span className="help">
                                Suggested from the organization slug, which is the only scope you can publish under. Your archives must use exactly these names.{' '}
                                {editing ? (
                                    <button type="button" className="sd-linkbtn" onClick={() => setEditing(false)}>
                                        Use the suggested names
                                    </button>
                                ) : (
                                    <button type="button" className="sd-linkbtn" onClick={startEditing}>
                                        Edit names
                                    </button>
                                )}
                            </span>
                        </div>

                        <div className="grid2">
                            <Field label="Category" optional error={fieldError('category')}>
                                {(id, aria) => (
                                    <select id={id} {...aria} className="select" value={category} onChange={(e) => setCategory(e.target.value)}>
                                        <option value="">Choose later</option>
                                        {categories.map((c) => (
                                            <option key={c.slug} value={c.slug}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </Field>
                            <div className="field">
                                <span className="label" id="hosting-label">
                                    Hosting
                                </span>
                                <Segmented role="radio" label="Hosting" options={HOSTING} value={hosting} onChange={setHosting} />
                                <span className="help">{HOSTING.find((h) => h.value === hosting).help}</span>
                            </div>
                        </div>

                        <div className="row between wrapflex" style={{ borderTop: '1px solid var(--line)', paddingTop: 20 }}>
                            <span className="small muted">
                                Prefer the CLI? <code>flb publish</code> creates the listing from extension.json.
                            </span>
                            <button type="submit" className="btn btn-primary btn-lg" disabled={!organization || tooShort || busy} aria-busy={busy || undefined}>
                                {busy ? 'Creating…' : 'Create draft'}
                            </button>
                        </div>
                    </form>

                    <aside className="rail" aria-label="Preview">
                        <span className="eyebrow">How it will look</span>
                        <div className="ext-card" style={{ boxShadow: 'var(--e2)' }} data-preview>
                            <div className="row" style={{ alignItems: 'flex-start' }}>
                                <ExtensionIcon extension={{ name: name || 'Untitled extension', slug: slugify(name) }} />
                                <div className="grow">
                                    <div className="h4">{name.trim() || 'Untitled extension'}</div>
                                    <div className="small muted">{organization?.name ?? 'Your organization'}</div>
                                </div>
                                <span className="price price-free">Free</span>
                            </div>
                            <p className="small muted">Your short description goes here.</p>
                            <div className="row between xs muted">
                                <span>Draft · not published</span>
                                <span>{categoryName ?? 'No category yet'}</span>
                            </div>
                        </div>
                        <Route
                            mini
                            label="Next: listing, release, review, live"
                            stops={[
                                { label: 'Listing', state: 'now' },
                                { label: 'Release', state: 'todo' },
                                { label: 'Review', state: 'todo' },
                                { label: 'Live', state: 'todo' },
                            ]}
                        />
                    </aside>
                </div>
            </div>
        </SiteLayout>
    );
}
