import { Link, usePage } from '@inertiajs/react';
import { shortDate } from '../../lib/format';
import { PageHeader } from '../Ui';
import './account.css';

const SECTIONS = [
    { key: 'organizations', label: 'Organizations', href: '/account/organizations' },
    { key: 'billing', label: 'Billing', href: '/account/billing' },
    { key: 'profile', label: 'Profile', href: '/account/profile' },
    { key: 'tokens', label: 'Tokens', href: '/account/tokens' },
];

/**
 * The account pages' heading, with the Organizations · Billing · Profile · Tokens switcher above
 * the title (PageHeader), so it is in the same place on every account page.
 */
export default function AccountHeader({ title = 'Account', current, description, actions }) {
    const account = usePage().props.auth?.account;
    const subtitle =
        description ??
        (account ? [account.email, account.email_verified_at ? `verified ${shortDate(account.email_verified_at)}` : 'not verified'].join(' · ') : null);

    return (
        <PageHeader
            title={title}
            description={subtitle}
            actions={actions}
            nav={
                <nav className="seg" aria-label="Account sections">
                    {SECTIONS.map((section) => (
                        <Link key={section.key} href={section.href} aria-current={section.key === current ? 'page' : undefined}>
                            {section.label}
                        </Link>
                    ))}
                </nav>
            }
        />
    );
}
