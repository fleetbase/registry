import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ExtensionCard from '../../components/ExtensionCard';
import Icon from '../../components/Icon';
import { EmptyState, Segmented } from '../../components/Ui';
import { Reviewed, Verified, WorksWith } from '../../components/Trust';
import { plural } from '../../lib/format';

/** Intent shortcuts: what an operator wants to do, mapped to a category. */
const INTENTS = [
    { category: 'telematics', title: 'Track vehicles', hint: 'Telematics and GPS devices', icon: 'truck' },
    { category: 'warehouse-management', title: 'Run a warehouse', hint: 'Receiving, bins, picking', icon: 'warehouse' },
    { category: 'route-optimization', title: 'Plan better routes', hint: 'Optimisation and dispatch', icon: 'route' },
    { category: 'e-commerce', title: 'Sell and get paid', hint: 'Storefront, payments, rates', icon: 'card' },
    { category: 'accounting', title: 'Connect accounting', hint: 'Ledgers, invoices, payroll', icon: 'ledger' },
    { category: 'customer-relationship-management', title: 'Talk to customers', hint: 'Notifications and tracking', icon: 'chat' },
];

const SORTS = [
    { value: 'popular', label: 'Most installed' },
    { value: 'updated', label: 'Recently updated' },
    { value: 'newest', label: 'Newest' },
    { value: 'name', label: 'Name' },
];

/**
 * Discover: search as the hero, intent shortcuts, filters, results. Filters live in the URL so
 * a search can be shared; "works with my console" filters by the remembered console version.
 */
export default function Discover({ filters, results, categories }) {
    const { console: consoleVersion } = usePage().props;
    const [search, setSearch] = useState(filters.q ?? '');
    const [version, setVersion] = useState(consoleVersion ?? '');
    const [editingVersion, setEditingVersion] = useState(false);

    // The server filters to listings with a release for the remembered console version unless
    // `compat=any`, so counts and pages cover the whole catalog.
    const onlyCompatible = Boolean(consoleVersion) && filters.compat !== 'any';
    const extensions = results.data ?? [];
    const meta = results.meta ?? {};
    const category = categories.find((c) => c.slug === filters.category);
    const intent = INTENTS.find((i) => i.category === filters.category);
    const hasFilters = Boolean(filters.q || filters.category || filters.pricing || filters.hosting);

    function visit(next) {
        const query = { ...filters, ...next };
        Object.keys(query).forEach((key) => (query[key] === null || query[key] === undefined || query[key] === '') && delete query[key]);
        if (!('page' in next)) {
            delete query.page;
        }
        router.get('/', query, { preserveState: true, preserveScroll: 'page' in next ? false : true, replace: true });
    }

    function submitSearch(event) {
        event.preventDefault();
        visit({ q: search.trim() || null });
    }

    function saveVersion(event) {
        event.preventDefault();
        router.post('/console', { version: version.trim() || null }, { preserveScroll: true, onSuccess: () => setEditingVersion(false) });
    }

    return (
        <SiteLayout title={filters.q ? `“${filters.q}”` : category ? category.name : 'Discover'}>
            <section className="hero">
                <div className="wrap" style={{ paddingTop: 72, paddingBottom: 56 }}>
                    <div className="col" style={{ gap: 20, maxWidth: 820 }}>
                        <span className="eyebrow">Extensions for Fleetbase</span>
                        <h1 className="display">Add what your operation needs next.</h1>
                        <p className="lead" style={{ maxWidth: 640 }}>
                            Engines and integrations for Fleet-Ops, Storefront and the rest of your console. Every listing shows who built it, whether Fleetbase reviewed it, and if it runs on your version.
                        </p>
                        <form className="searchbox" role="search" action="/" method="get" onSubmit={submitSearch}>
                            <Icon name="search" size={22} className="muted" />
                            <input name="q" aria-label="Search extensions" placeholder="Try “dock appointments” or “telematics”" value={search} onChange={(event) => setSearch(event.target.value)} data-catalog-search />
                            <kbd className="kbd hide-sm">⌘ K</kbd>
                            <button type="submit" className="btn btn-primary">
                                Search
                            </button>
                        </form>
                        <div className="row wrapflex" style={{ gap: 10 }}>
                            {consoleVersion && !editingVersion ? (
                                <>
                                    <button type="button" className="compat" aria-pressed={onlyCompatible} onClick={() => visit({ compat: onlyCompatible ? 'any' : null })} data-compat-toggle>
                                        <Icon name="monitor" size={16} />
                                        {onlyCompatible ? `Works with my console: ${consoleVersion}` : 'Any console version'}
                                    </button>
                                    <button type="button" className="btn btn-quiet btn-sm" onClick={() => setEditingVersion(true)}>
                                        Change version
                                    </button>
                                </>
                            ) : (
                                <form className="row wrapflex" style={{ gap: 8 }} action="/console" method="post" onSubmit={saveVersion}>
                                    <label className="small ink2" htmlFor="console-version">
                                        Your console version
                                    </label>
                                    <input id="console-version" name="version" className="input mono" style={{ width: 120, minHeight: 40 }} placeholder="0.7.69" value={version} onChange={(event) => setVersion(event.target.value)} />
                                    <button type="submit" className="btn btn-sm">
                                        Show what works
                                    </button>
                                    <span className="xs muted">Settings → About in your console shows it.</span>
                                </form>
                            )}
                        </div>
                    </div>
                </div>
            </section>

            <div className="wrap" style={{ paddingTop: 40, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <section className="stack" aria-labelledby="h-intent">
                        <div className="row between">
                            <h2 id="h-intent" className="h3">
                                Start from what you want to do
                            </h2>
                        </div>
                        <div className="grid3">
                            {INTENTS.map((item) => {
                                const on = filters.category === item.category;
                                return (
                                    <button key={item.category} type="button" className="intent" aria-pressed={on} onClick={() => visit({ category: on ? null : item.category })} style={{ font: 'inherit', textAlign: 'left', cursor: 'pointer', borderColor: on ? 'var(--accent)' : undefined }} data-intent={item.category}>
                                        <span className="ig" aria-hidden="true">
                                            <Icon name={item.icon} size={20} />
                                        </span>
                                        <span className="col" style={{ gap: 2 }}>
                                            <span className="h4">{item.title}</span>
                                            <span className="small muted">{item.hint}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <section className="stack" aria-labelledby="h-results">
                        <div className="row between wrapflex" style={{ gap: 16 }}>
                            <div className="col" style={{ gap: 4 }}>
                                <h2 id="h-results" className="h3">
                                    {filters.q ? `Results for “${filters.q}”` : intent ? intent.title : category ? category.name : 'Popular right now'}
                                </h2>
                                <span className="small muted num" data-results-count>
                                    {plural(meta.total ?? extensions.length, 'extension')}
                                    {onlyCompatible ? ` that work with console ${consoleVersion}` : ''}
                                </span>
                            </div>
                            <div className="row wrapflex" style={{ gap: 10 }}>
                                <Segmented
                                    label="Price"
                                    value={filters.pricing ?? 'all'}
                                    onChange={(value) => visit({ pricing: value === 'all' ? null : value })}
                                    options={[
                                        { value: 'all', label: 'All' },
                                        { value: 'free', label: 'Free' },
                                        { value: 'paid', label: 'Paid' },
                                    ]}
                                />
                                <label className="sr-only" htmlFor="hosting">
                                    Hosting
                                </label>
                                <select id="hosting" className="select" style={{ width: 'auto', minHeight: 40, borderRadius: 999, padding: '0 14px' }} value={filters.hosting ?? ''} onChange={(event) => visit({ hosting: event.target.value || null })}>
                                    <option value="">Any hosting</option>
                                    <option value="cloud">Fleetbase Cloud</option>
                                    <option value="self_hosted">Self-hosted</option>
                                </select>
                                <label className="sr-only" htmlFor="category">
                                    Category
                                </label>
                                <select id="category" className="select" style={{ width: 'auto', maxWidth: 220, minHeight: 40, borderRadius: 999, padding: '0 14px' }} value={filters.category ?? ''} onChange={(event) => visit({ category: event.target.value || null })}>
                                    <option value="">All categories</option>
                                    {categories
                                        .filter((c) => c.extensions_count || c.slug === filters.category)
                                        .map((c) => (
                                            <option key={c.slug} value={c.slug}>
                                                {c.name} ({c.extensions_count ?? 0})
                                            </option>
                                        ))}
                                </select>
                                <label className="sr-only" htmlFor="sort">
                                    Sort
                                </label>
                                <select id="sort" className="select" style={{ width: 'auto', minHeight: 40, borderRadius: 999, padding: '0 14px' }} value={filters.sort ?? 'popular'} onChange={(event) => visit({ sort: event.target.value === 'popular' ? null : event.target.value })}>
                                    {SORTS.map((sort) => (
                                        <option key={sort.value} value={sort.value}>
                                            {sort.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {extensions.length > 0 ? (
                            <>
                                <div className="grid3" data-results>
                                    {extensions.map((extension) => (
                                        <ExtensionCard key={extension.slug} extension={extension} console={consoleVersion} />
                                    ))}
                                </div>
                                {(meta.last_page ?? 1) > 1 && (
                                    <nav className="row between wrapflex" aria-label="Pages">
                                        <span className="small muted">
                                            Showing {meta.from}–{meta.to} of {meta.total}
                                        </span>
                                        <div className="row">
                                            <button type="button" className="btn btn-sm" disabled={meta.current_page <= 1} onClick={() => visit({ page: meta.current_page - 1 })}>
                                                Previous
                                            </button>
                                            <button type="button" className="btn btn-sm" disabled={meta.current_page >= meta.last_page} onClick={() => visit({ page: meta.current_page + 1 })}>
                                                Next
                                            </button>
                                        </div>
                                    </nav>
                                )}
                            </>
                        ) : (
                            <div className="card">
                                {hasFilters || onlyCompatible ? (
                                    <EmptyState
                                        icon="search"
                                        title={filters.q ? `No extensions match “${filters.q}”` : hasFilters ? 'No extensions match these filters' : `Nothing works with console ${consoleVersion} yet`}
                                        actions={
                                            <>
                                                {onlyCompatible && (
                                                    <button type="button" className="btn" onClick={() => visit({ compat: 'any' })}>
                                                        Show all console versions
                                                    </button>
                                                )}
                                                {hasFilters && (
                                                    <button type="button" className="btn btn-quiet" onClick={() => router.get('/', {}, { preserveScroll: true })} data-clear-filters>
                                                        Clear search and filters
                                                    </button>
                                                )}
                                            </>
                                        }
                                    >
                                        {hasFilters
                                            ? 'Search covers names, subtitles, tags and publishers. Try a broader term, or clear a filter.'
                                            : 'Each release says which console versions it supports. Show every version, or update your console.'}
                                    </EmptyState>
                                ) : (
                                    <EmptyState icon="box" title="No extensions are published yet" actions={<Link href="/studio" className="btn btn-primary">Publish the first one</Link>}>
                                        Extensions appear here once a publisher submits one and Fleetbase approves it.
                                    </EmptyState>
                                )}
                            </div>
                        )}
                    </section>

                    <section className="card card-pad" aria-labelledby="h-trust">
                        <div className="row wrapflex" style={{ gap: 32, alignItems: 'flex-start' }}>
                            <div className="col" style={{ gap: 6, maxWidth: 300 }}>
                                <h2 id="h-trust" className="h4">
                                    What the marks mean
                                </h2>
                                <p className="small muted">Three marks, one fact each. No stars, no badges for sale.</p>
                            </div>
                            <div className="col grow" style={{ gap: 6, minWidth: 220 }}>
                                <Verified short />
                                <span className="small muted">Fleetbase confirmed who the publisher is.</span>
                            </div>
                            <div className="col grow" style={{ gap: 6, minWidth: 220 }}>
                                <Reviewed short />
                                <span className="small muted">A person at Fleetbase reviewed the latest release.</span>
                            </div>
                            <div className="col grow" style={{ gap: 6, minWidth: 220 }}>
                                <WorksWith version={consoleVersion ?? 'your console'} />
                                <span className="small muted">The release’s console range includes your version.</span>
                            </div>
                        </div>
                    </section>

                    <section className="row wrapflex between card card-pad" style={{ background: 'var(--accent-soft)', borderColor: 'var(--accent-line)', gap: 24 }}>
                        <div className="col" style={{ gap: 6, maxWidth: 640 }}>
                            <h2 className="h3">Build for fleets everywhere</h2>
                            <p className="ink2">Publish a console engine and API package with one command. Eight automated checks run on every release, and Fleetbase reviews first releases.</p>
                        </div>
                        <div className="row wrapflex">
                            <Link href="/studio" className="btn btn-primary">
                                Start publishing
                            </Link>
                            <a href="https://docs.fleetbase.io/extensions" className="btn" target="_blank" rel="noopener noreferrer">
                                Developer guide
                            </a>
                        </div>
                    </section>
                </div>
            </div>
        </SiteLayout>
    );
}
