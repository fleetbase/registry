import { Link, usePage } from '@inertiajs/react';
import Icon from '../Icon';
import ExtensionIcon from '../ExtensionIcon';
import './studio.css';

/** A Studio page header: back button, the listing's icon, a title and one line under it. */
export function StudioHeader({ extension, title, sub, back = '/studio', backLabel = 'Back to my extensions', children }) {
    return (
        <div className="row wrapflex" style={{ gap: 16 }}>
            <Link href={back} className="icon-btn" aria-label={backLabel} style={{ border: '1px solid var(--line)' }}>
                <Icon name="arrowLeft" />
            </Link>
            {extension && <ExtensionIcon extension={extension} />}
            <div className="col grow" style={{ gap: 2, minWidth: 200 }}>
                <h1 className="h2">{title}</h1>
                {sub && <span className="small muted">{sub}</span>}
            </div>
            {children}
        </div>
    );
}

/** Roles that see an organization's payouts, and roles that publish (and see insights). */
const BILLING_ROLES = ['owner', 'admin', 'billing'];
const PUBLISHING_ROLES = ['owner', 'admin', 'publisher'];

/**
 * Studio sections: Extensions · Payouts · Insights. `organization` (a slug, or null for all) is the
 * organization the switcher shows; each tab's link keeps it when that tab can show it, so moving
 * between tabs stays on the same organization.
 */
export function StudioNav({ current, organization = null }) {
    const { auth } = usePage().props ?? {};
    const role = organization ? (auth?.account?.organizations ?? []).find((item) => item.slug === organization)?.role : null;
    const slug = organization ? encodeURIComponent(organization) : null;
    const items = [
        { href: slug ? `/studio?org=${slug}` : '/studio', label: 'Extensions', key: 'extensions' },
        { href: slug && BILLING_ROLES.includes(role) ? `/studio/payouts/${slug}` : '/studio/payouts', label: 'Payouts', key: 'payouts' },
        { href: slug && PUBLISHING_ROLES.includes(role) ? `/studio/insights/${slug}` : '/studio/insights', label: 'Insights', key: 'insights' },
    ];
    return (
        <nav className="seg" aria-label="Studio sections">
            {items.map((item) => (
                <Link key={item.key} href={item.href} aria-current={current === item.key ? 'page' : undefined}>
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}

/**
 * Which organization Studio shows, above the Studio tabs: a labelled dropdown, or just the name
 * when there is only one. `allowAll` adds "All organizations" (the Extensions tab); `onPick`
 * gets the chosen slug, or null for all.
 */
export function StudioOrganization({ organizations = [], current = null, allowAll = false, onPick }) {
    if (!organizations.length) {
        return null;
    }
    if (organizations.length === 1) {
        return (
            <div className="row studio-org" style={{ gap: 8 }} data-org-switch>
                <span className="small muted">Organization</span>
                <span className="small" style={{ fontWeight: 600, color: 'var(--ink)' }}>
                    {organizations[0].name}
                </span>
            </div>
        );
    }
    return (
        <label className="row studio-org" style={{ gap: 8, flexWrap: 'nowrap' }} data-org-switch>
            <span className="small ink2">Organization</span>
            <select className="select select-sm" style={{ width: 'auto', maxWidth: 260 }} value={current?.slug ?? ''} onChange={(event) => onPick(event.target.value || null)} data-organization-picker>
                {(allowAll || !current) && <option value="">All organizations</option>}
                {organizations.map((item) => (
                    <option key={item.id} value={item.slug}>
                        {item.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

/** The listing's sub-pages, for the Releases, Review, History and Access headers. */
export function ListingTabs({ extension, current }) {
    const base = `/studio/${extension.slug}`;
    const items = [
        { href: base, label: 'Listing', key: 'listing' },
        { href: `${base}/releases`, label: 'Releases', key: 'releases' },
        { href: `${base}/review`, label: 'Review', key: 'review' },
        { href: `${base}/history`, label: 'History', key: 'history' },
        { href: `${base}/access`, label: 'Access', key: 'access' },
    ];
    return (
        <nav className="seg" aria-label={`${extension.name} sections`} style={{ maxWidth: '100%', overflowX: 'auto' }}>
            {items.map((item) => (
                <Link key={item.key} href={item.href} aria-current={current === item.key ? 'page' : undefined}>
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}

/** The publishing organizations of the signed-in account (owner, admin or publisher). */
export function publishingOrganizations(account) {
    return (account?.organizations ?? []).filter((organization) => ['owner', 'admin', 'publisher'].includes(organization.role));
}

/** The six review requirements (the reviewer's R-items). */
export const REQUIREMENTS = {
    R1: 'Manifest is valid',
    R2: 'Installs on a clean instance',
    R3: 'Listing is complete',
    R4: 'No secrets or credentials',
    R5: 'Permissions go through the auth schema',
    R6: 'Pricing matches Stripe Connect',
};
