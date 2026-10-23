import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Icon from '../components/Icon';
import CommandPalette from '../components/CommandPalette';
import { ToastProvider } from '../components/Toasts';
import { api } from '../lib/api';
import { initials } from '../lib/format';
import { useTheme } from '../lib/theme';

const TAGS = { public: 'Extensions', studio: 'Studio', review: 'Review' };

/**
 * Every page's frame: the top bar (logo, Discover / Studio / Review, ⌘K search, theme toggle,
 * account menu), the page, the footer, toasts and the command palette.
 */
export default function SiteLayout({ title, section = 'public', children, wide = false }) {
    const { auth } = usePage().props;
    const account = auth?.account;
    const { isDark, toggle } = useTheme();
    const [palette, setPalette] = useState(false);
    const url = usePage().url;

    useEffect(() => {
        function onKey(event) {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setPalette((open) => !open);
            }
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const nav = [
        { href: '/', label: 'Discover', current: section === 'public' && (url === '/' || url.startsWith('/?') || url.startsWith('/extensions') || url.startsWith('/publishers')) },
        { href: '/studio', label: account ? 'Studio' : 'Publish', current: section === 'studio' },
        ...(account?.is_staff ? [{ href: '/review', label: 'Review', current: section === 'review' }] : []),
    ];

    return (
        <ToastProvider>
            <Head title={title} />
            <a href="#main" className="sr-only focus:not-sr-only" style={{ position: 'absolute', left: 8, top: 8, zIndex: 80 }}>
                Skip to content
            </a>
            <div className="flex min-h-screen flex-col">
                <header className="top">
                    <div className="wrap">
                        <Link href={section === 'studio' ? '/studio' : section === 'review' ? '/review' : '/'} className="logo">
                            <span className="logo-mark" aria-hidden="true">
                                <Icon name="logo" size={16} strokeWidth={2.4} />
                            </span>
                            Fleetbase <span className="tag">{TAGS[section]}</span>
                        </Link>
                        <nav className="nav" aria-label="Main">
                            {nav.map((item) => (
                                <Link key={item.href} href={item.href} aria-current={item.current ? 'page' : undefined}>
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                        <button type="button" className="kbar" aria-label="Search (Ctrl K)" onClick={() => setPalette(true)} data-palette-open>
                            <Icon name="search" />
                            <span className="kbar-text grow" style={{ textAlign: 'left' }}>
                                {account ? 'Search or jump to…' : 'Search'}
                            </span>
                            <kbd className="kbd">⌘ K</kbd>
                        </button>
                        {section === 'review' && (
                            <span className="hide-sm" style={{ display: 'inline-flex', alignItems: 'center', height: 28, padding: '0 10px', borderRadius: 999, background: '#06172a', color: '#e6f0fb', fontSize: 12, fontWeight: 600 }}>
                                Fleetbase staff
                            </span>
                        )}
                        <button type="button" className="icon-btn" aria-label={isDark ? 'Switch to light theme' : 'Switch to dark theme'} onClick={toggle} data-theme-toggle>
                            <Icon name={isDark ? 'sun' : 'moon'} size={20} />
                        </button>
                        {account ? (
                            <AccountMenu account={account} />
                        ) : (
                            <>
                                <Link href="/sign-in" className="btn btn-sm hide-sm" data-sign-in>
                                    Sign in
                                </Link>
                                <Link href="/register" className="btn btn-sm btn-primary hide-sm">
                                    Start publishing
                                </Link>
                                <Link href="/sign-in" className="icon-btn show-sm" aria-label="Sign in">
                                    <Icon name="user" size={20} />
                                </Link>
                            </>
                        )}
                    </div>
                </header>
                <nav className="show-sm" aria-label="Sections" style={{ gap: 4, padding: '8px 16px', borderBottom: '1px solid var(--line)', overflowX: 'auto' }}>
                    {nav.map((item) => (
                        <Link key={item.href} href={item.href} className="chip" aria-current={item.current ? 'page' : undefined} aria-pressed={item.current}>
                            {item.label}
                        </Link>
                    ))}
                </nav>
                <main id="main" className={`grow ${wide ? '' : ''}`}>
                    {children}
                </main>
                <footer style={{ borderTop: '1px solid var(--line)' }}>
                    <div className="wrap row between wrapflex" style={{ paddingTop: 24, paddingBottom: 32, gap: 16 }}>
                        <span className="small muted">Fleetbase Extensions Registry</span>
                        <nav className="row wrapflex small" aria-label="Footer" style={{ gap: 20 }}>
                            <a href="https://docs.fleetbase.io/extensions" target="_blank" rel="noopener noreferrer">
                                Developer guide
                            </a>
                            <a href="https://fleetbase.io/privacy" target="_blank" rel="noopener noreferrer">
                                Privacy
                            </a>
                            <a href="https://fleetbase.io/terms" target="_blank" rel="noopener noreferrer">
                                Terms
                            </a>
                            <a href="https://github.com/fleetbase/registry" target="_blank" rel="noopener noreferrer">
                                GitHub
                            </a>
                        </nav>
                    </div>
                </footer>
            </div>
            <CommandPalette open={palette} onClose={() => setPalette(false)} />
        </ToastProvider>
    );
}

function AccountMenu({ account }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

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
            }
        }
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    async function signOut() {
        try {
            await api.delete('auth/session');
        } finally {
            router.visit('/', { replace: true });
        }
    }

    const items = [
        ['My extensions', '/studio'],
        ['Organizations', '/account/organizations'],
        ['Tokens', '/account/tokens'],
        ['Profile and password', '/account/profile'],
        ...(account.is_staff ? [['Review console', '/review']] : []),
    ];

    return (
        <div ref={ref} style={{ position: 'relative' }}>
            <button type="button" className="avatar" style={{ border: 0, cursor: 'pointer' }} aria-haspopup="menu" aria-expanded={open} aria-label={`Account menu for ${account.name}`} onClick={() => setOpen(!open)} data-account-menu>
                {initials(account.name)}
            </button>
            {open && (
                <div role="menu" className="card" style={{ position: 'absolute', right: 0, top: 'calc(100% + 8px)', width: 260, padding: 6, zIndex: 40, boxShadow: 'var(--e2)' }}>
                    <div style={{ padding: '10px 12px', borderBottom: '1px solid var(--line)', marginBottom: 6 }}>
                        <div className="h4">{account.name}</div>
                        <div className="xs muted" style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>
                            {account.email}
                        </div>
                    </div>
                    {items.map(([label, href]) => (
                        <Link key={href} href={href} role="menuitem" className="row" style={{ minHeight: 40, padding: '0 12px', borderRadius: 8, color: 'var(--ink)' }} onClick={() => setOpen(false)}>
                            {label}
                        </Link>
                    ))}
                    <button type="button" role="menuitem" className="row" style={{ width: '100%', minHeight: 40, padding: '0 12px', borderRadius: 8, border: 0, background: 'transparent', color: 'var(--ink)', font: 'inherit', cursor: 'pointer', borderTop: '1px solid var(--line)', marginTop: 6 }} onClick={signOut} data-sign-out>
                        Sign out
                    </button>
                </div>
            )}
        </div>
    );
}
