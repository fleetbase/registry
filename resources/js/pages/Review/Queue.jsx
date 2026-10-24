import { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import ExtensionIcon from '../../components/ExtensionIcon';
import { ReleaseStatus, StatusChip } from '../../components/Status';
import { EmptyState } from '../../components/Ui';
import { ChecksChip, ReviewTabs } from '../../components/review/Parts';
import { QUEUE_FILTERS, filterReleases, queueAge, reasonTags, sortOldestFirst, updateLabel } from '../../components/review/queue';
import { isPaid, priceLabel, timeAgo } from '../../lib/format';

const AGE_COLOURS = { new: 'var(--ok)', in: 'var(--you)', over: 'var(--fail)' };

const EMPTY = {
    waiting: { icon: 'check', title: 'Nothing waiting for review', body: 'Every submitted release has a decision. New submissions appear here, oldest first.' },
    decided: { icon: 'gavel', title: 'No decisions yet', body: 'Published and rejected releases appear here.' },
    failed: { icon: 'check', title: 'No releases with failed checks', body: 'Releases whose automated checks failed appear here until the publisher uploads a fix.' },
};

const HEADINGS = {
    waiting: { title: 'Review queue', lead: 'Oldest first. Target: a decision within 3 business days.', label: 'Releases waiting for review' },
    decided: { title: 'Decided', lead: 'Published and rejected releases, oldest first.', label: 'Decided releases' },
    failed: { title: 'Failed checks', lead: 'Releases whose automated checks failed. The publisher fixes and uploads again.', label: 'Releases with failed checks' },
};

/**
 * The staff review queue: one tab per state, filter chips applied in the browser, one row per
 * release (oldest first) linking to its review page. J and K move between rows, Enter opens.
 */
export default function Queue({ tab = 'waiting', releases, claimers = {}, counts = {} }) {
    const { auth } = usePage().props;
    const [filters, setFilters] = useState([]);
    const all = useMemo(() => sortOldestFirst(releases?.data ?? []), [releases]);
    const rows = useMemo(() => filterReleases(all, filters), [all, filters]);
    const heading = HEADINGS[tab] ?? HEADINGS.waiting;
    const now = new Date();

    function toggle(key) {
        setFilters((list) => (list.includes(key) ? list.filter((item) => item !== key) : [...list, key]));
    }

    function onListKey(event) {
        const key = event.key.toLowerCase();
        if (key !== 'j' && key !== 'k') {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        const links = [...event.currentTarget.querySelectorAll('[data-queue-row]')];
        if (!links.length) {
            return;
        }
        event.preventDefault();
        const index = links.indexOf(document.activeElement);
        const next = index === -1 ? 0 : Math.min(links.length - 1, Math.max(0, index + (key === 'j' ? 1 : -1)));
        links[next].focus();
    }

    return (
        <SiteLayout title={heading.title} section="review">
            <div className="wrap" style={{ paddingTop: 36, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <div className="row between wrapflex" style={{ gap: 16 }}>
                        <div className="col" style={{ gap: 6 }}>
                            <h1 className="h1">{heading.title}</h1>
                            <p className="ink2">{heading.lead}</p>
                        </div>
                        <ReviewTabs current={tab} counts={counts} />
                    </div>

                    {all.length > 0 && (
                        <div className="row wrapflex" style={{ gap: 8 }}>
                            {QUEUE_FILTERS.map((filter) => (
                                <button key={filter.key} type="button" className="chip" style={{ minHeight: 44 }} aria-pressed={filters.includes(filter.key)} onClick={() => toggle(filter.key)} data-filter={filter.key}>
                                    {filter.label}
                                </button>
                            ))}
                            <span className="grow" />
                            <span className="small muted hide-sm">
                                <span className="kbd">J</span> <span className="kbd">K</span> move · <span className="kbd">↵</span> open
                            </span>
                        </div>
                    )}

                    <section className="card" aria-label={heading.label}>
                        {rows.length ? (
                            <ul role="list" onKeyDown={onListKey} style={{ listStyle: 'none', margin: 0, padding: 0 }} data-queue>
                                {rows.map((release, index) => (
                                    <li key={release.id} style={{ borderTop: index ? '1px solid var(--line)' : 0 }}>
                                        <QueueRow release={release} tab={tab} now={now} claimer={claimers[release.id]} me={auth?.account?.id} />
                                    </li>
                                ))}
                            </ul>
                        ) : all.length ? (
                            <EmptyState
                                icon="search"
                                title="No releases match these filters"
                                actions={
                                    <button type="button" className="btn" onClick={() => setFilters([])}>
                                        Clear filters
                                    </button>
                                }
                            >
                                {`${all.length} ${all.length === 1 ? 'release is' : 'releases are'} hidden by the filters.`}
                            </EmptyState>
                        ) : (
                            <EmptyState icon={(EMPTY[tab] ?? EMPTY.waiting).icon} title={(EMPTY[tab] ?? EMPTY.waiting).title}>
                                {(EMPTY[tab] ?? EMPTY.waiting).body}
                            </EmptyState>
                        )}
                    </section>

                    {releases?.meta?.total > all.length && <p className="small muted">Showing the oldest {all.length} of {releases.meta.total}.</p>}
                    {tab === 'waiting' && <p className="small muted">The queue lists submitted and in-review releases. Approved releases that were not published at once wait under To publish.</p>}
                </div>
            </div>
        </SiteLayout>
    );
}

function QueueRow({ release, tab, now, claimer, me }) {
    const extension = release.extension ?? {};
    const organization = extension.organization;
    const age = queueAge(release, now);
    const paid = isPaid(extension.price);

    return (
        <Link
            href={`/review/releases/${release.id}`}
            className="grid items-center gap-x-4 gap-y-2.5 p-4 text-ink no-underline hover:bg-surface-2 hover:text-ink hover:no-underline focus-visible:bg-surface-2 min-[900px]:grid-cols-[72px_minmax(0,1.6fr)_minmax(0,1.2fr)_minmax(0,1fr)_auto] min-[900px]:gap-5 min-[900px]:px-6 min-[900px]:py-[18px] grid-cols-[56px_minmax(0,1fr)]"
            style={{ textDecoration: 'none' }}
            data-queue-row
            data-release={release.id}
        >
            {tab === 'waiting' ? (
                <div className="col" style={{ gap: 6 }} data-age={age.state}>
                    <span className="num" style={{ fontSize: 22, fontWeight: 650, letterSpacing: '-.02em' }}>
                        {age.label}
                    </span>
                    <span aria-hidden="true" style={{ height: 6, borderRadius: 999, background: 'var(--bg-tint)', overflow: 'hidden' }}>
                        <span style={{ display: 'block', height: '100%', width: `${age.percent}%`, borderRadius: 999, background: AGE_COLOURS[age.state] }} />
                    </span>
                    <span className="xs" style={{ color: age.state === 'over' ? 'var(--fail)' : 'var(--muted)', fontWeight: age.state === 'over' ? 600 : 400 }}>
                        {age.text}
                    </span>
                </div>
            ) : (
                <span className="small muted">{timeAgo(release.updated_at, now)}</span>
            )}

            <div className="row" style={{ gap: 12, minWidth: 0 }}>
                <ExtensionIcon extension={extension} size="sm" />
                <span className="col" style={{ gap: 2, minWidth: 0 }}>
                    <span className="h4">
                        {extension.name} <span className="mono small muted">v{release.version}</span>
                    </span>
                    <span className="small muted">
                        {organization?.name ?? 'Unknown publisher'}
                        {organization?.is_verified ? ' · verified' : ''}
                    </span>
                </span>
            </div>

            <div className="row wrapflex col-start-2 min-[900px]:col-start-auto" style={{ gap: 6 }}>
                <span className="tag" data-update>
                    {updateLabel(release)}
                </span>
                {paid && <span className="tag">Paid · {priceLabel(extension.price)}</span>}
                {reasonTags(release).map((tag) => (
                    <span key={tag} className="tag">
                        {tag}
                    </span>
                ))}
            </div>

            <div className="col-start-2 min-[900px]:col-start-auto">
                <ChecksChip release={release} />
            </div>

            <div className="col-start-2 min-[900px]:col-start-auto">
                {release.status === 'in_review' && claimer?.name ? (
                    <StatusChip tone="wait" icon="eye" data-status="in_review">
                        {claimer.id && claimer.id === me ? 'In review · you' : `In review · ${claimer.name}`}
                    </StatusChip>
                ) : (
                    <ReleaseStatus status={release.status} />
                )}
            </div>
        </Link>
    );
}
