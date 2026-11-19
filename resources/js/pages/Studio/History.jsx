import { useState } from 'react';
import SiteLayout from '../../layouts/SiteLayout';
import Timeline from '../../components/Timeline';
import Route, { releaseStops } from '../../components/Route';
import { ReleaseStatus } from '../../components/Status';
import { EmptyState, Note, Segmented } from '../../components/Ui';
import { ListingTabs, REQUIREMENTS, StudioHeader } from '../../components/studio/StudioChrome';

export const FILTERS = {
    all: null,
    decisions: ['submit', 'claim', 'approve', 'reject', 'publish', 'withdraw', 'yank'],
    uploads: ['upload', 'check', 'pass_checks', 'fail_checks'],
};

/**
 * The listing's history, one group per release (newest first) with its timeline. The oldest
 * group ends with "Listing created". Filters: all, decisions, uploads.
 */
export function historyGroups(extension, releases, filter = 'all') {
    const keep = FILTERS[filter];
    const created = { action: 'created', icon: 'plus', text: 'Listing created', created_at: extension.created_at };
    const groups = releases.map((release, index) => {
        let entries = release.reviews ?? [];
        if (index === releases.length - 1 && filter === 'all') {
            entries = [created, ...entries];
        }
        return { release, entries: keep ? entries.filter((entry) => keep.includes(entry.action)) : entries };
    });
    if (!releases.length && filter === 'all') {
        groups.push({ release: null, entries: [created] });
    }
    return groups.filter((group) => group.entries.length > 0);
}

export default function StudioHistory({ extension, releases = [], truncated = false }) {
    const [filter, setFilter] = useState('all');
    const groups = historyGroups(extension, releases, filter);

    return (
        <SiteLayout title={`History · ${extension.name}`} section="studio">
            <div className="wrap" style={{ paddingTop: 28, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <StudioHeader extension={extension} title="History" sub={`${extension.name} · every upload, check and decision`} back={`/studio/${extension.slug}`} backLabel={`Back to ${extension.name}`}>
                        <Segmented
                            label="Filter"
                            value={filter}
                            onChange={setFilter}
                            options={[
                                { value: 'all', label: 'All' },
                                { value: 'decisions', label: 'Decisions' },
                                { value: 'uploads', label: 'Uploads' },
                            ]}
                        />
                    </StudioHeader>
                    <ListingTabs extension={extension} current="history" />

                    {truncated && (
                        <Note tone="info">
                            <span className="small">Showing the 20 newest releases.</span>
                        </Note>
                    )}

                    <section className="card card-pad" aria-label="Releases">
                        {groups.length ? (
                            groups.map(({ release, entries }) => (
                                <div key={release?.id ?? 'listing'} className="sd-rel-group" data-history-release={release?.version ?? 'listing'}>
                                    <div className="col" style={{ gap: 8, alignItems: 'flex-start' }}>
                                        {release ? (
                                            <>
                                                <h2 className="h3 mono">v{release.version}</h2>
                                                <ReleaseStatus status={release.status} />
                                                <span aria-hidden="true" style={{ marginTop: 8 }}>
                                                    <Route stops={releaseStops(release).map((stop) => ({ ...stop, label: '' }))} mini />
                                                </span>
                                            </>
                                        ) : (
                                            <h2 className="h3">Listing</h2>
                                        )}
                                    </div>
                                    <Timeline entries={entries} itemLabels={REQUIREMENTS} />
                                </div>
                            ))
                        ) : (
                            <EmptyState icon="clock" title="Nothing here yet">
                                {filter === 'decisions' ? 'No submissions or review decisions yet.' : 'No uploads yet.'}
                            </EmptyState>
                        )}
                    </section>
                </div>
            </div>
        </SiteLayout>
    );
}
