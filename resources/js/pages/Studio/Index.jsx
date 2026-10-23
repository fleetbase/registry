import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Icon from '../../components/Icon';
import ExtensionIcon from '../../components/ExtensionIcon';
import CopyCommand from '../../components/CopyCommand';
import Route, { releaseStops } from '../../components/Route';
import { ListingStatus, StatusChip } from '../../components/Status';
import { Note } from '../../components/Ui';
import { StudioNav, publishingOrganizations } from '../../components/studio/StudioChrome';
import { groupInbox, listingState, studioUrl } from '../../components/studio/inbox';
import { initials, plural, priceLabel, shortDate } from '../../lib/format';
import { releaseStatus } from '../../lib/statuses';

/**
 * Studio home: the organization switcher, the inbox ("Your move" / "With Fleetbase"), then every
 * listing with its status, live version, price, the route of its open release and one action.
 * With no listings, the three-step first-run guide.
 */
export default function StudioIndex({ extensions = [], organization = null }) {
    const { auth } = usePage().props;
    const organizations = publishingOrganizations(auth?.account);
    const current = organizations.find((org) => org.slug === organization) ?? null;
    const shown = current ? extensions.filter((extension) => extension.organization?.slug === current.slug) : extensions;
    const inbox = groupInbox(shown);
    const firstRun = extensions.length === 0;

    function pick(slug) {
        router.replace({
            url: slug ? `/studio?org=${encodeURIComponent(slug)}` : '/studio',
            props: (props) => ({ ...props, organization: slug }),
            preserveState: true,
            preserveScroll: true,
        });
    }

    return (
        <SiteLayout title="My extensions" section="studio">
            <div className="wrap" style={{ paddingTop: 36, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <div className="row between wrapflex" style={{ gap: 16 }}>
                        <div className="col" style={{ gap: 10 }}>
                            {organizations.length > 0 && <OrganizationSwitcher organizations={organizations} current={current} onPick={pick} />}
                            <h1 className="h1">My extensions</h1>
                        </div>
                        <div className="row wrapflex">
                            <span className="hide-sm">
                                <StudioNav current="extensions" />
                            </span>
                            <Link href="/studio/new" className="btn btn-primary">
                                <Icon name="plus" size={16} strokeWidth={2.2} />
                                New extension
                            </Link>
                        </div>
                    </div>

                    {organizations.length === 0 && (
                        <Note tone="you">
                            You are not an owner, admin or publisher of any organization yet, so there is nothing to publish as. <Link href="/account/organizations">Create an organization</Link> or ask an owner to add you as a publisher.
                        </Note>
                    )}

                    {firstRun ? (
                        <FirstRun />
                    ) : (
                        <>
                            <div className="grid2" style={{ alignItems: 'start' }}>
                                <section className="stack" aria-labelledby="h-you" data-inbox="yours">
                                    <div className="row" style={{ gap: 10 }}>
                                        <StatusChip tone="you" icon="alert">
                                            Your move
                                        </StatusChip>
                                        <h2 id="h-you" className="h4">
                                            {inbox.yours.length ? `${plural(inbox.yours.length, 'thing')} ${inbox.yours.length === 1 ? 'needs' : 'need'} you` : 'Nothing needs you'}
                                        </h2>
                                    </div>
                                    {inbox.yours.map((item) => (
                                        <InboxItem key={item.extension.id} item={item} />
                                    ))}
                                </section>
                                <section className="stack" aria-labelledby="h-fb" data-inbox="fleetbase">
                                    <div className="row" style={{ gap: 10 }}>
                                        <StatusChip tone="wait" icon="clock">
                                            With Fleetbase
                                        </StatusChip>
                                        <h2 id="h-fb" className="h4">
                                            {inbox.fleetbase.length ? 'Nothing to do here' : 'Nothing in review'}
                                        </h2>
                                    </div>
                                    {inbox.fleetbase.map((item) => (
                                        <InboxItem key={item.extension.id} item={item} />
                                    ))}
                                </section>
                            </div>

                            <section className="card" aria-labelledby="h-all">
                                <div className="card-head">
                                    <h2 id="h-all" className="h3">
                                        All extensions
                                    </h2>
                                    <span className="small muted">
                                        {plural(shown.length, 'listing')}
                                        {current ? ` · ${current.name}` : ''}
                                    </span>
                                </div>
                                {shown.length ? (
                                    <div>
                                        {shown.map((extension) => (
                                            <ExtensionRow key={extension.id} extension={extension} />
                                        ))}
                                    </div>
                                ) : (
                                    <div className="card-body">
                                        <p className="ink2">{current?.name} has no listings yet.</p>
                                    </div>
                                )}
                            </section>
                        </>
                    )}

                    <div className="show-sm">
                        <StudioNav current="extensions" />
                    </div>
                </div>
            </div>
        </SiteLayout>
    );
}

function OrganizationSwitcher({ organizations, current, onPick }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    const label = current?.name ?? (organizations.length === 1 ? organizations[0].name : 'All organizations');

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        function onDown(event) {
            if (!ref.current?.contains(event.target)) {
                setOpen(false);
            }
        }
        function onKey(event) {
            if (event.key === 'Escape') {
                setOpen(false);
                ref.current?.querySelector('button')?.focus();
            }
        }
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    if (organizations.length === 1) {
        return (
            <span className="sd-org-switch" style={{ cursor: 'default' }} data-org-switch>
                <span className="avatar" style={{ width: 32, height: 32, fontSize: 12 }} aria-hidden="true">
                    {initials(label)}
                </span>
                <span>Publishing as {label}</span>
            </span>
        );
    }

    const choose = (slug) => {
        setOpen(false);
        onPick(slug);
    };

    return (
        <div ref={ref} style={{ position: 'relative', alignSelf: 'flex-start' }} data-org-switch>
            <button type="button" className="sd-org-switch" aria-expanded={open} aria-haspopup="true" aria-label={`Showing ${label}. Switch organization`} onClick={() => setOpen((value) => !value)}>
                <span className="avatar" style={{ width: 32, height: 32, fontSize: 12 }} aria-hidden="true">
                    {current ? initials(current.name) : <Icon name="building" size={16} />}
                </span>
                {label}
                <Icon name="chevronDown" size={14} strokeWidth={2.4} />
            </button>
            {open && (
                <ul className="sd-org-menu" aria-label="Organizations">
                    <li>
                        <button type="button" aria-current={current ? undefined : 'true'} onClick={() => choose(null)}>
                            <Icon name="building" size={16} />
                            All organizations
                        </button>
                    </li>
                    {organizations.map((org) => (
                        <li key={org.id}>
                            <button type="button" aria-current={current?.slug === org.slug ? 'true' : undefined} onClick={() => choose(org.slug)}>
                                <span className="avatar" style={{ width: 28, height: 28, fontSize: 11 }} aria-hidden="true">
                                    {initials(org.name)}
                                </span>
                                <span className="col grow" style={{ gap: 2 }}>
                                    <span>{org.name}</span>
                                    <span className="xs muted">
                                        {org.is_personal ? 'Personal' : org.role.charAt(0).toUpperCase() + org.role.slice(1)} · @{org.slug}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function InboxItem({ item }) {
    return (
        <Link href={item.href} className="sd-inbox-item" data-inbox-item={item.kind}>
            <ExtensionIcon extension={item.extension} size="sm" />
            <span className="col grow" style={{ gap: 2 }}>
                <span className="h4">{item.title}</span>
                <span className="small muted">{item.detail}</span>
            </span>
            <Icon name="arrowRight" size={18} />
        </Link>
    );
}

function ExtensionRow({ extension }) {
    const { open, live, missing, incomplete, action } = listingState(extension);
    const status = open ? releaseStatus(open.status) : null;

    let stops = null;
    if (open) {
        stops = releaseStops(open);
        stops[0] = { ...stops[0], label: `v${open.version}`, sub: 'uploaded' };
        if (incomplete) {
            stops = [{ label: 'Listing', sub: `${missing.length} to fix`, state: 'you' }, ...stops];
        }
    } else if (incomplete) {
        stops = [
            { label: 'Listing', sub: `${missing.length} to fix`, state: 'you' },
            { label: 'Release', state: 'todo' },
            { label: 'Review', state: 'todo' },
            { label: 'Live', state: 'todo' },
        ];
    }
    const routeLabel = open ? `v${open.version}: ${status.label}${incomplete ? `; listing needs ${missing.length} more` : ''}` : `Listing needs ${missing.length} more`;

    return (
        <div className="sd-ext-row" data-extension={extension.slug}>
            <Link href={studioUrl.editor(extension)} className="row" style={{ color: 'var(--ink)', textDecoration: 'none' }}>
                <ExtensionIcon extension={extension} />
                <span className="sd-who">
                    <span className="h4">{extension.name}</span>
                    <span className="row wrapflex" style={{ gap: 8 }}>
                        {extension.status === 'published' && live ? (
                            <StatusChip tone="ok" icon="check">
                                Live v{live.version}
                            </StatusChip>
                        ) : (
                            <ListingStatus status={extension.status} />
                        )}
                        <span className="small muted">{priceLabel(extension.price)}</span>
                    </span>
                </span>
            </Link>
            {stops ? (
                <div>
                    <Route stops={stops} label={routeLabel} className="hide-sm" />
                    <Route stops={stops} label={routeLabel} mini className="show-sm" />
                </div>
            ) : (
                <div className="col" style={{ gap: 4 }}>
                    <span className="small ink2">{live ? 'Up to date. No release in progress.' : 'No release yet.'}</span>
                    {live?.published_at && <span className="xs muted">Last published {shortDate(live.published_at)}</span>}
                </div>
            )}
            <div className="row sd-actions" style={{ justifyContent: 'flex-end', gap: 8 }}>
                <Link href={action.href} className={`btn btn-sm ${action.primary ? 'btn-primary' : ''}`} aria-label={`${action.label}: ${extension.name}`}>
                    {action.label}
                </Link>
            </div>
        </div>
    );
}

function FirstRun() {
    return (
        <section className="stack" aria-labelledby="h-first" data-first-run>
            <div className="col" style={{ gap: 8, maxWidth: 720 }}>
                <h2 id="h-first" className="h2">
                    Publish your first extension
                </h2>
                <p className="lead">An extension is a console engine (npm) and an API package (Composer). Three steps; the first two can happen in either order.</p>
            </div>
            <ol className="grid3" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                <li className="sd-bigstep">
                    <span className="n" aria-hidden="true">
                        1
                    </span>
                    <h3 className="h3">Create the listing</h3>
                    <p className="ink2">Name, packages, category. Copy, screenshots and pricing can wait and never need review.</p>
                    <Link href="/studio/new" className="btn btn-primary" style={{ alignSelf: 'flex-start' }}>
                        Create listing
                    </Link>
                </li>
                <li className="sd-bigstep">
                    <span className="n" aria-hidden="true">
                        2
                    </span>
                    <h3 className="h3">Upload a release</h3>
                    <p className="ink2">Drop the archives into the listing, or publish from your project. Eight checks run at once.</p>
                    <CopyCommand value="flb publish" />
                </li>
                <li className="sd-bigstep">
                    <span className="n" aria-hidden="true">
                        3
                    </span>
                    <h3 className="h3">Submit for review</h3>
                    <p className="ink2">The readiness list says what’s missing. Fleetbase reviews first releases, then it goes live everywhere.</p>
                </li>
            </ol>
            <Note tone="info">
                Publishing from the CLI needs a token with the publish scope. <Link href="/account/tokens">Create one</Link>
            </Note>
        </section>
    );
}
