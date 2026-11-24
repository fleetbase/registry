import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Dialog from '../../components/Dialog';
import Icon from '../../components/Icon';
import { VisibilityChip } from '../../components/Status';
import { useToast } from '../../components/Toasts';
import { EmptyState, Note } from '../../components/Ui';
import { ListingTabs, StudioHeader } from '../../components/studio/StudioChrome';
import GrantsSection from '../../components/studio/GrantsSection';
import TrustedPublishersSection from '../../components/studio/TrustedPublishersSection';
import { hasReleased } from '../../components/studio/access';
import { api } from '../../lib/api';

/**
 * Who can see, install and publish a listing (owners and admins): its visibility, the
 * organizations granted access, and the GitHub workflows trusted to publish it.
 */
export default function StudioAccess({ extension, access = null, trusted_publishers: trustedPublishers = [], forbidden = null }) {
    const visibility = access?.visibility ?? extension.visibility ?? 'public';
    const pending = access ? access.pending_visibility : (extension.pending_visibility ?? null);

    return (
        <SiteLayout title={`Access · ${extension.name}`} section="studio">
            <div className="wrap page">
                <div className="stack-lg">
                    <StudioHeader extension={extension} title={extension.name} sub="Who can see, install and publish it" back={`/studio/${extension.slug}`} backLabel={`Back to ${extension.name}`}>
                        <VisibilityChip extension={{ visibility, pending_visibility: pending }} showPublic />
                    </StudioHeader>
                    <ListingTabs extension={extension} current="access" />

                    {forbidden ? (
                        <div className="card" data-forbidden>
                            <EmptyState
                                icon="lock"
                                title="Only owners and admins can manage access"
                                actions={
                                    <Link href={`/studio/${extension.slug}`} className="btn">
                                        Back to the listing
                                    </Link>
                                }
                            >
                                Visibility, access grants and trusted publishing for {extension.name} are managed by the owners and admins of {extension.organization?.name ?? 'its organization'}. Ask one of them to make the change.
                            </EmptyState>
                        </div>
                    ) : (
                        <>
                            <VisibilitySection extension={extension} visibility={visibility} pending={pending} />
                            <GrantsSection extension={extension} grants={access?.grants ?? []} visibility={visibility} />
                            <TrustedPublishersSection extension={extension} publishers={trustedPublishers ?? []} />
                        </>
                    )}
                </div>
            </div>
        </SiteLayout>
    );
}

/** What switching does, by direction (and, going public, whether anything was released). */
function consequences(target, released) {
    if (target === 'private') {
        return [
            'It leaves the catalog and search straight away, and no one without access can install it.',
            'Your organization, and the organizations you grant access below, can still see, install and update it. Organizations that bought it keep their licences.',
            'Private releases skip the Fleetbase review. They’re still checked automatically, and publish as soon as the checks pass.',
        ];
    }
    if (released) {
        return [
            'It stays private until your next release passes a Fleetbase review. The review treats it like a first release, so the listing has to be complete.',
            'When that release is published, the listing appears in the catalog and search and anyone can install it.',
            'You can cancel the request until then.',
        ];
    }
    return ['It becomes public straight away. Nothing has been released yet, so it shows in the catalog once its first release passes review and is published.'];
}

function VisibilitySection({ extension, visibility, pending }) {
    const toast = useToast();
    const [confirming, setConfirming] = useState(null); // 'public' | 'private'
    const [busy, setBusy] = useState(false);
    const released = hasReleased(extension);

    async function change(target, { cancel = false } = {}) {
        setBusy(true);
        try {
            const updated = await api.patch(`/publisher/extensions/${extension.slug}`, { visibility: target });
            if (cancel) {
                toast(`${extension.name} stays private. The request to go public is cancelled.`);
            } else if (updated?.pending_visibility === 'public') {
                toast(`${extension.name} goes public when its next release passes review.`);
            } else if ((updated?.visibility ?? target) === 'private') {
                toast(`${extension.name} is private. Only your organization and organizations with access can see it.`);
            } else {
                toast(`${extension.name} is public.`);
            }
            setConfirming(null);
            router.reload({ only: ['extension', 'access'] });
        } catch (e) {
            toast(e.message, { type: 'error' });
        } finally {
            setBusy(false);
        }
    }

    const options = [
        { value: 'public', label: 'Public', help: 'Listed in the catalog and search. Anyone can install it, or buy it when it is paid.' },
        { value: 'private', label: 'Private', help: 'Hidden from the catalog and search. Only your organization and organizations you grant access can see and install it.' },
    ];

    function choose(value) {
        if (value === visibility || (value === 'public' && pending === 'public')) {
            return;
        }
        setConfirming(value);
    }

    return (
        <section id="s-visibility" className="card" aria-labelledby="h-visibility" data-visibility={visibility} data-pending={pending ?? undefined}>
            <div className="card-head">
                <h2 id="h-visibility" className="h3">
                    Visibility
                </h2>
            </div>
            <div className="card-body stack">
                <div className="grid2" role="radiogroup" aria-labelledby="h-visibility" style={{ gap: 10 }}>
                    {options.map((option) => (
                        <button key={option.value} type="button" className="sd-opt-card" role="radio" aria-checked={visibility === option.value} onClick={() => choose(option.value)} data-visibility-option={option.value}>
                            <span className="sd-radio" aria-hidden="true" />
                            <span className="col" style={{ gap: 2 }}>
                                <span className="h4 row" style={{ gap: 6 }}>
                                    <Icon name={option.value === 'private' ? 'lock' : 'globe'} size={16} />
                                    {option.label}
                                </span>
                                <span className="small muted">{option.help}</span>
                            </span>
                        </button>
                    ))}
                </div>

                {pending === 'public' ? (
                    <Note tone="you" data-pending-public>
                        <div className="col" style={{ gap: 8 }}>
                            <strong>Going public with the next reviewed release</strong>
                            <span className="small">
                                {extension.name} stays private until a release passes a Fleetbase review. The review treats it like a first release, so the listing has to be complete. When that release is published, it appears in the catalog.
                            </span>
                            <div className="row wrapflex" style={{ gap: 8 }}>
                                <Link href={`/studio/${extension.slug}/releases`} className="btn btn-sm">
                                    Upload a release
                                </Link>
                                <button type="button" className="btn btn-sm btn-quiet" onClick={() => change('private', { cancel: true })} disabled={busy} data-cancel-public>
                                    Keep it private
                                </button>
                            </div>
                        </div>
                    </Note>
                ) : visibility === 'private' ? (
                    <span className="small muted">Private releases skip the Fleetbase review; they’re still checked automatically, and publish when the checks pass. The listing only needs a name.</span>
                ) : null}
            </div>

            <Dialog
                open={Boolean(confirming)}
                title={confirming === 'private' ? `Make ${extension.name} private?` : released ? `Ask to make ${extension.name} public?` : `Make ${extension.name} public?`}
                labelledBy="visibility-dialog-title"
                onClose={() => setConfirming(null)}
                footer={
                    <>
                        <button type="button" className="btn" onClick={() => setConfirming(null)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-primary" onClick={() => change(confirming)} disabled={busy} data-confirm-visibility>
                            {busy && <Icon name="spinner" spin />}
                            {confirming === 'private' ? 'Make private' : released ? 'Go public after review' : 'Make public'}
                        </button>
                    </>
                }
            >
                <ul className="stack ink2" style={{ margin: 0, paddingLeft: 18 }}>
                    {consequences(confirming, released).map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            </Dialog>
        </section>
    );
}
