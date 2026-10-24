import { Link, usePage } from '@inertiajs/react';
import { shortDate } from '../../lib/format';
import './account.css';

const SECTIONS = [
    { key: 'organizations', label: 'Organizations', href: '/account/organizations' },
    { key: 'billing', label: 'Billing', href: '/account/billing' },
    { key: 'profile', label: 'Profile', href: '/account/profile' },
    { key: 'tokens', label: 'Tokens', href: '/account/tokens' },
];

/** The account pages' heading with the Organizations · Billing · Profile · Tokens switcher. */
export default function AccountHeader({ title = 'Account', current, description, actions }) {
    const account = usePage().props.auth?.account;
    const subtitle =
        description ??
        (account ? [account.email, account.email_verified_at ? `verified ${shortDate(account.email_verified_at)}` : 'not verified'].join(' · ') : null);

    return (
        <div className="row between wrapflex" style={{ gap: 16 }}>
            <div className="col" style={{ gap: 6, maxWidth: 640 }}>
                <h1 className="h1">{title}</h1>
                {subtitle && <p className="ink2">{subtitle}</p>}
            </div>
            <div className="row wrapflex">
                <nav className="seg" aria-label="Account sections">
                    {SECTIONS.map((section) => {
                        const selected = section.key === current;
                        return (
                            <Link
                                key={section.key}
                                href={section.href}
                                aria-current={selected ? 'page' : undefined}
                                style={selected ? { background: 'var(--surface)', color: 'var(--ink)', boxShadow: 'var(--e1), 0 0 0 1px var(--line)', minHeight: 44 } : { minHeight: 44 }}
                            >
                                {section.label}
                            </Link>
                        );
                    })}
                </nav>
                {actions}
            </div>
        </div>
    );
}
