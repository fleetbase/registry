import { Link } from '@inertiajs/react';
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

/** Studio sections: Extensions · Payouts · Insights. */
export function StudioNav({ current }) {
    const items = [
        { href: '/studio', label: 'Extensions', key: 'extensions' },
        { href: '/studio/payouts', label: 'Payouts', key: 'payouts' },
        { href: '/studio/insights', label: 'Insights', key: 'insights' },
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

/** The listing's sub-pages, for the Releases, Review and History headers. */
export function ListingTabs({ extension, current }) {
    const base = `/studio/${extension.slug}`;
    const items = [
        { href: base, label: 'Listing', key: 'listing' },
        { href: `${base}/releases`, label: 'Releases', key: 'releases' },
        { href: `${base}/review`, label: 'Review', key: 'review' },
        { href: `${base}/history`, label: 'History', key: 'history' },
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
