import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ExtensionIcon from '../../components/ExtensionIcon';
import Icon from '../../components/Icon';
import { StatusChip } from '../../components/Status';
import { StudioNav } from '../../components/studio/StudioChrome';
import { EmptyState, Note } from '../../components/Ui';
import TrendChart from '../../components/insights/TrendChart';
import { GROUPS, PRESETS, activePreset, change, csvHref, dayLabel, eventStatus, eventVersion, presetRange, rangeLabel, revenueIn, revenueLine, versionRows } from '../../components/insights/insights';
import { dateTime, money, plural } from '../../lib/format';
import '../../components/insights/insights.css';

const RIGHT = { textAlign: 'right' };

/** Drop empty values from a query. */
function query(values) {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

/**
 * Insights: installs, updates, uninstalls, active instances, purchases and revenue for one of the
 * organization's listings (with active installs by version) or all of them, over a date range and
 * against the period before it, with recent events and a CSV of the series.
 */
export default function Insights({ organizations = [], organization, extensions = [], extension = null, stats, filters = {}, error }) {
    const selected = extensions.find((item) => item.slug === extension) ?? null;

    function visit(changes) {
        const range = stats?.range ?? {};
        const next = query({ extension, from: range.from, to: range.to, group: filters.group, ...changes });
        router.get(`/studio/insights/${organization.slug}`, next, { preserveScroll: true, preserveState: true, only: ['stats', 'filters', 'extension', 'error'] });
    }

    return (
        <SiteLayout title="Insights" section="studio">
            <div className="wrap" style={{ paddingTop: 36, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <div className="row between wrapflex" style={{ gap: 16 }}>
                        <div className="col" style={{ gap: 6 }}>
                            <h1 className="h1">Insights</h1>
                            {organization && (
                                <p className="ink2">
                                    {selected ? selected.name : `All extensions of ${organization.name}`}
                                    {stats ? `, ${rangeLabel(stats.range)}` : ''}
                                </p>
                            )}
                        </div>
                        <StudioNav current="insights" />
                    </div>
                    {organizations.length > 1 && (
                        <nav className="seg" aria-label="Organization" style={{ alignSelf: 'flex-start', maxWidth: '100%', overflowX: 'auto' }}>
                            {organizations.map((item) => (
                                <Link key={item.id} href={`/studio/insights/${item.slug}`} aria-current={item.slug === organization?.slug ? 'page' : undefined}>
                                    {item.name}
                                </Link>
                            ))}
                        </nav>
                    )}
                    {!organization ? (
                        <EmptyState icon="chart" title="No organization to show">
                            Insights are for organization owners, admins and publishers. Ask an owner to give you the publisher role.
                        </EmptyState>
                    ) : extensions.length === 0 ? (
                        <EmptyState
                            icon="chart"
                            title="No extensions yet"
                            actions={
                                <Link href="/studio/new" className="btn btn-primary">
                                    <Icon name="plus" />
                                    New extension
                                </Link>
                            }
                        >
                            Installs, uninstalls and sales show here once {organization.name} has a listing.
                        </EmptyState>
                    ) : (
                        <>
                            <Filters organization={organization} extensions={extensions} extension={extension} stats={stats} filters={filters} onChange={visit} />
                            {error && (
                                <Note tone="fail" role="alert">
                                    <span className="small">{error}</span>
                                </Note>
                            )}
                            {stats && <Report stats={stats} single={Boolean(selected)} pickable={extensions.map((item) => item.slug)} onPick={(slug) => visit({ extension: slug })} />}
                        </>
                    )}
                </div>
            </div>
        </SiteLayout>
    );
}

/** One row above everything it scopes: listing, range presets, a custom range, grouping, CSV. */
function Filters({ organization, extensions, extension, stats, filters, onChange }) {
    const range = stats?.range;
    const [from, setFrom] = useState(range?.from ?? filters.from ?? '');
    const [to, setTo] = useState(range?.to ?? filters.to ?? '');
    const preset = activePreset(range);

    function custom(event) {
        event.preventDefault();
        onChange({ from, to, group: null });
    }

    function pickPreset(days) {
        const next = presetRange(days);
        setFrom(next.from);
        setTo(next.to);
        onChange({ ...next, group: null });
    }

    return (
        <section className="card card-pad col" style={{ gap: 14 }} aria-label="What to show" data-filters>
            <div className="row wrapflex" style={{ gap: 12, alignItems: 'flex-end' }}>
                <label className="field" style={{ minWidth: 220 }}>
                    <span className="label">Extension</span>
                    <select className="select" value={extension ?? ''} onChange={(e) => onChange({ extension: e.target.value || null })} data-filter="extension">
                        <option value="">All extensions</option>
                        {extensions.map((item) => (
                            <option key={item.slug} value={item.slug}>
                                {item.name}
                                {item.status !== 'published' ? ` (${item.status.replace(/_/g, ' ')})` : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="field" style={{ maxWidth: '100%', minWidth: 0 }}>
                    <span className="label" id="ins-presets">
                        Range
                    </span>
                    <div className="seg ins-presets" role="group" aria-labelledby="ins-presets">
                        {PRESETS.map((item) => (
                            <button key={item.days} type="button" aria-pressed={preset === item.days} onClick={() => pickPreset(item.days)} data-preset={item.days}>
                                {item.label}
                            </button>
                        ))}
                    </div>
                </div>
                <label className="field">
                    <span className="label">Group by</span>
                    <select className="select" value={range?.group ?? filters.group ?? 'day'} onChange={(e) => onChange({ group: e.target.value })} data-filter="group">
                        {GROUPS.map((item) => (
                            <option key={item.value} value={item.value}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="grow" />
                <a className="btn btn-sm" href={csvHref({ organization: organization.slug, extension, range })} download data-csv>
                    <Icon name="download" />
                    Export CSV
                </a>
            </div>
            <form className="row wrapflex" style={{ gap: 12, alignItems: 'flex-end' }} onSubmit={custom} aria-label="Custom range">
                <label className="field">
                    <span className="label">From</span>
                    <input type="date" className="input" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} required />
                </label>
                <label className="field">
                    <span className="label">To</span>
                    <input type="date" className="input" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} required />
                </label>
                <button type="submit" className="btn" style={{ minHeight: 44 }}>
                    Show
                </button>
                {stats && (
                    <span className="xs muted grow" style={{ textAlign: 'right' }} data-as-of>
                        As of {dateTime(stats.as_of)} · days in UTC · compared with {rangeLabel(stats.previous)}
                    </span>
                )}
            </form>
        </section>
    );
}

function Report({ stats, single, pickable, onPick }) {
    return (
        <>
            <Tiles stats={stats} />
            <div className={single ? 'ins-split' : 'ins-stack'}>
                <section className="card" aria-labelledby="h-trend">
                    <div className="card-head">
                        <h2 id="h-trend" className="h3">
                            Installs and uninstalls per {stats.range.group}
                        </h2>
                    </div>
                    <div className="card-body">
                        <TrendChart series={stats.series} group={stats.range.group} partialFirst={stats.series[0]?.date < stats.range.from} partialLast={stats.as_of.slice(0, 10) <= stats.range.to} />
                    </div>
                </section>
                {single ? <Versions versions={stats.versions ?? []} /> : <ByExtension rows={stats.extensions ?? []} pickable={pickable} onPick={onPick} />}
            </div>
            <Events events={stats.events} showExtension={!single} />
        </>
    );
}

function Tile({ label, value, delta, hint, ...rest }) {
    return (
        <div className="card card-pad col" style={{ gap: 4 }} {...rest}>
            <span className="small muted">{label}</span>
            <span className="h2">{value}</span>
            {delta && (
                <span className={`xs ins-delta ins-${delta.tone}`} data-delta={delta.tone}>
                    {delta.text}
                    <span className="muted"> {delta.suffix}</span>
                </span>
            )}
            {hint && <span className="xs muted">{hint}</span>}
        </div>
    );
}

/** Headline figures, each against the previous period of the same length. */
function Tiles({ stats }) {
    const { totals, previous_totals: before } = stats;
    const vs = `vs previous ${plural(stats.range.days, 'day')}`;
    const lead = totals.revenue[0] ?? before.revenue[0] ?? null;
    const currency = lead?.currency ?? 'USD';
    const now = revenueIn(totals.revenue, currency);
    const then = revenueIn(before.revenue, currency);

    return (
        <section className="ins-tiles" aria-label="Totals">
            <Tile label="Installs" value={totals.installs.toLocaleString('en-US')} delta={{ ...change(totals.installs, before.installs), suffix: vs }} hint={totals.updates ? `${plural(totals.updates, 'update')} besides` : null} data-stat="installs" />
            <Tile label="Uninstalls" value={totals.uninstalls.toLocaleString('en-US')} delta={{ ...change(totals.uninstalls, before.uninstalls, { goodWhenUp: false, absolute: true }), suffix: vs }} data-stat="uninstalls" />
            <Tile
                label="Active instances"
                value={totals.active_instances.toLocaleString('en-US')}
                delta={{ ...change(totals.active_instances, before.active_instances, { absolute: true }), suffix: `since ${dayLabel(stats.range.from)}` }}
                data-stat="active_instances"
            />
            <Tile label="Purchases" value={totals.purchases.toLocaleString('en-US')} delta={{ ...change(totals.purchases, before.purchases), suffix: vs }} data-stat="purchases" />
            <Tile
                label={`Net revenue${lead ? ` (${currency})` : ''}`}
                value={money(now.net, currency)}
                delta={lead ? { ...change(now.net, then.net, { format: (value) => money(value, currency) }), suffix: vs } : null}
                hint={totals.revenue.length > 1 ? `Also ${revenueLine(totals.revenue.slice(1))}` : now.refunded ? `After ${money(now.fee, currency)} in fees and ${money(now.refunded, currency)} refunded` : 'After Fleetbase’s fee and refunds'}
                data-stat="net"
            />
        </section>
    );
}

/** Active installs by version, newest first, the tail folded into one row. */
function Versions({ versions }) {
    const rows = versionRows(versions);
    const total = versions.reduce((sum, row) => sum + row.instances, 0);

    return (
        <section className="card" aria-labelledby="h-versions" data-versions>
            <div className="card-head">
                <h2 id="h-versions" className="h3">
                    Active by version
                </h2>
                <span className="xs muted">{plural(total, 'instance')}</span>
            </div>
            <div className="card-body col" style={{ gap: 14 }}>
                {rows.length === 0 ? (
                    <p className="small muted">No instance runs this extension right now.</p>
                ) : (
                    <ul className="col" style={{ gap: 14, listStyle: 'none', margin: 0, padding: 0 }}>
                        {rows.map((row) => (
                            <li key={row.label} className="col" style={{ gap: 6 }} data-version={row.version ?? 'older'}>
                                <div className="row between small">
                                    <span>{row.label}</span>
                                    <span>
                                        <strong className="num">{row.instances.toLocaleString('en-US')}</strong>
                                        <span className="muted"> · {Math.round(row.share * 100)}%</span>
                                    </span>
                                </div>
                                <div className="ins-bar" aria-hidden="true">
                                    <span style={{ width: `${Math.max(row.share * 100, row.instances ? 1.5 : 0)}%` }} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="xs muted">Instances report the release they install, so this shows when it is safe to stop supporting an old version.</p>
            </div>
        </section>
    );
}

/** One row per listing of the organization, for the all-extensions view. */
function ByExtension({ rows, pickable = [], onPick }) {
    return (
        <section className="card" aria-labelledby="h-by-extension" data-by-extension>
            <div className="card-head">
                <h2 id="h-by-extension" className="h3">
                    By extension
                </h2>
            </div>
            <div className="tbl-wrap">
                <table className="tbl">
                    <thead>
                        <tr>
                            <th scope="col">Extension</th>
                            <th scope="col" style={RIGHT}>
                                Installs
                            </th>
                            <th scope="col" style={RIGHT}>
                                Uninstalls
                            </th>
                            <th scope="col" style={RIGHT}>
                                Active
                            </th>
                            <th scope="col" style={RIGHT}>
                                Purchases
                            </th>
                            <th scope="col" style={RIGHT}>
                                Net revenue
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.extension.id} data-extension-row={row.extension.slug}>
                                <th scope="row" style={{ fontWeight: 500 }}>
                                    <span className="row" style={{ gap: 10 }}>
                                        <ExtensionIcon extension={row.extension} size="sm" />
                                        {!pickable.includes(row.extension.slug) ? (
                                            <span>
                                                {row.extension.name} <span className="xs muted">(deleted)</span>
                                            </span>
                                        ) : (
                                            <button type="button" className="linkish" onClick={() => onPick(row.extension.slug)}>
                                                {row.extension.name}
                                            </button>
                                        )}
                                    </span>
                                </th>
                                <td className="num" style={RIGHT}>
                                    {row.installs}
                                </td>
                                <td className="num" style={RIGHT}>
                                    {row.uninstalls}
                                </td>
                                <td className="num" style={RIGHT}>
                                    {row.active_instances}
                                </td>
                                <td className="num" style={RIGHT}>
                                    {row.purchases}
                                </td>
                                <td className="num" style={RIGHT}>
                                    {row.revenue.length ? revenueLine(row.revenue) : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function Events({ events, showExtension }) {
    const rows = events?.data ?? [];

    return (
        <section className="card" aria-labelledby="h-events" data-events>
            <div className="card-head">
                <h2 id="h-events" className="h3">
                    Recent events
                </h2>
                {events?.total > rows.length && <span className="xs muted">Latest {rows.length} of {events.total.toLocaleString('en-US')} in this range</span>}
            </div>
            {rows.length === 0 ? (
                <div className="card-body">
                    <p className="small muted">Nothing happened in this range. Installs, updates, uninstalls and sales show here as instances report them.</p>
                </div>
            ) : (
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th scope="col">When</th>
                                <th scope="col">Event</th>
                                {showExtension && <th scope="col">Extension</th>}
                                <th scope="col">Organization</th>
                                <th scope="col">Version</th>
                                <th scope="col" style={RIGHT}>
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((event, index) => {
                                const status = eventStatus(event);
                                return (
                                    <tr key={`${event.type}-${event.at}-${index}`} data-event={event.type}>
                                        <td className="nowrap muted">{dateTime(event.at)}</td>
                                        <td>
                                            <StatusChip tone={status.tone} icon={status.icon}>
                                                {status.label}
                                            </StatusChip>
                                        </td>
                                        {showExtension && <td>{event.extension?.name ?? '—'}</td>}
                                        <td>{event.organization?.name ?? '—'}</td>
                                        <td className="nowrap">{eventVersion(event)}</td>
                                        <td className="num nowrap" style={RIGHT}>
                                            {event.amount ? `${event.type === 'refund' ? '−' : ''}${money(event.amount, event.currency)}` : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
