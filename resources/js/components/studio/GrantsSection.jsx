import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import Dialog from '../Dialog';
import Icon from '../Icon';
import { StatusChip } from '../Status';
import { useToast } from '../Toasts';
import { EmptyState, Field, Note, Segmented } from '../Ui';
import { api } from '../../lib/api';
import { plural, shortDate, timeAgo } from '../../lib/format';
import { grantBody, grantDraft, instanceLabel, parseInstanceIds, parseSeats, seatsLabel, sortGrants, tomorrow } from './access';

const GRANT_STATUS = {
    active: { tone: 'ok', icon: 'check', label: 'Active' },
    expired: { tone: 'idle', icon: 'clock', label: 'Expired' },
    revoked: { tone: 'fail', icon: 'ban', label: 'Revoked' },
};

/** The first validation message for `instances` or any `instances.N`. */
function instancesError(error) {
    if (!error?.fields) {
        return null;
    }
    const key = Object.keys(error.fields).find((name) => name === 'instances' || name.startsWith('instances.'));
    return key ? error.field(key) : null;
}

/**
 * The organizations an extension is granted to: a table (organization, instances, seats,
 * expiry, status) with edit and revoke, and "Grant access". Revoked and expired grants stay
 * listed, dimmed.
 */
export default function GrantsSection({ extension, grants = [], visibility }) {
    const toast = useToast();
    const [editing, setEditing] = useState(null); // { grant } or { grant: null } for a new one
    const [revoking, setRevoking] = useState(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const sorted = sortGrants(grants);
    const active = grants.filter((grant) => grant.status === 'active').length;

    async function revoke() {
        setBusy(true);
        try {
            await api.delete(`/publisher/extensions/${extension.slug}/access/${revoking.id}`, reason.trim() ? { reason: reason.trim() } : undefined);
            toast(`Access revoked for ${revoking.organization?.name ?? 'the organization'}.`);
            setRevoking(null);
            router.reload({ only: ['access'] });
        } catch (error) {
            toast(error.message, { type: 'error' });
        } finally {
            setBusy(false);
        }
    }

    function startRevoke(grant) {
        setReason('');
        setRevoking(grant);
    }

    return (
        <section id="s-access" className="card" aria-labelledby="h-access" data-grants>
            <div className="card-head">
                <div className="col" style={{ gap: 2 }}>
                    <h2 id="h-access" className="h3">
                        Access
                    </h2>
                    <span className="small muted">
                        {active ? `${plural(active, 'organization')} can install it` : 'Organizations you grant can see and install it'}
                    </span>
                </div>
                <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing({ grant: null })} data-new-grant>
                    <Icon name="plus" />
                    Grant access
                </button>
            </div>

            {visibility === 'public' && (
                <div className="card-body" style={{ paddingBottom: 0 }}>
                    <Note tone="info">
                        <span className="small">The listing is public, so anyone can see it. Grants decide who can see and install it while it is private.</span>
                    </Note>
                </div>
            )}

            {sorted.length === 0 ? (
                <EmptyState icon="building" title="No organizations have access yet">
                    Grant access to a client or partner organization. You choose which of its instances can install it, how many at once and for how long.
                </EmptyState>
            ) : (
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th scope="col">Organization</th>
                                <th scope="col">Instances</th>
                                <th scope="col">Seats used</th>
                                <th scope="col">Expires</th>
                                <th scope="col">Status</th>
                                <th scope="col">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {sorted.map((grant) => (
                                <GrantRow key={grant.id} grant={grant} onEdit={() => setEditing({ grant })} onRevoke={() => startRevoke(grant)} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {editing && <GrantDialog extension={extension} grant={editing.grant} onClose={() => setEditing(null)} />}

            <Dialog
                open={Boolean(revoking)}
                title={`Revoke access for ${revoking?.organization?.name ?? 'this organization'}?`}
                labelledBy="revoke-grant-title"
                onClose={() => setRevoking(null)}
                footer={
                    <>
                        <button type="button" className="btn" onClick={() => setRevoking(null)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger-solid" onClick={revoke} disabled={busy} data-confirm-revoke>
                            {busy && <Icon name="spinner" spin />}
                            Revoke access
                        </button>
                    </>
                }
            >
                <div className="stack">
                    <p className="ink2">
                        This takes effect straight away: its instances can no longer download or update {extension.name}
                        {revoking?.seats_used ? `, and its ${plural(revoking.seats_used, 'seat')} in use ${revoking.seats_used === 1 ? 'is' : 'are'} released` : ''}. To give access back, grant it again.
                    </p>
                    <Field label="Reason" optional help="Kept with the grant’s history.">
                        {(id, aria) => <textarea id={id} {...aria} className="textarea" style={{ minHeight: 72 }} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
                    </Field>
                </div>
            </Dialog>
        </section>
    );
}

function GrantRow({ grant, onEdit, onRevoke }) {
    const status = GRANT_STATUS[grant.status] ?? GRANT_STATUS.expired;
    const dim = grant.status !== 'active';
    const name = grant.organization?.name ?? 'Unknown organization';
    const activations = grant.activations ?? [];

    return (
        <tr data-grant={grant.id} data-status={grant.status} className={dim ? 'sd-dim' : undefined}>
            <td>
                <div className="col" style={{ gap: 2, minWidth: 160 }}>
                    <span className="h4">{name}</span>
                    {grant.organization?.slug && <span className="small muted">@{grant.organization.slug}</span>}
                    {grant.note && (
                        <span className="small ink2" data-note>
                            {grant.note}
                        </span>
                    )}
                    {grant.status === 'revoked' && grant.revoked_reason && <span className="small muted">Revoked: {grant.revoked_reason}</span>}
                </div>
            </td>
            <td data-instances>
                {grant.instances?.length ? (
                    <ul className="col" style={{ gap: 4, listStyle: 'none', margin: 0, padding: 0 }}>
                        {grant.instances.map((instance) => (
                            <li key={instance.id} className="small">
                                {instanceLabel(instance)}
                                {instance.status && instance.status !== 'active' && <span className="muted"> ({instance.status})</span>}
                                <span className="mono xs muted" style={{ display: 'block' }}>
                                    {instance.id}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <span className="tag">All instances</span>
                )}
            </td>
            <td data-seats>
                <span className="num">{seatsLabel(grant)}</span>
                {activations.length > 0 && (
                    <details className="small" style={{ marginTop: 4 }}>
                        <summary className="muted" style={{ cursor: 'pointer' }}>
                            In use on {plural(activations.length, 'instance')}
                        </summary>
                        <ul className="col" style={{ gap: 4, listStyle: 'none', margin: '6px 0 0', padding: 0 }}>
                            {activations.map((activation, index) => (
                                <li key={activation.instance?.id ?? index}>
                                    {activation.instance ? instanceLabel(activation.instance) : 'Removed instance'}
                                    <span className="muted">
                                        {activation.release ? ` · v${activation.release}` : ''}
                                        {activation.activated_at ? ` · ${timeAgo(activation.activated_at)}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </td>
            <td className="small">{grant.status === 'revoked' ? (grant.revoked_at ? `Revoked ${shortDate(grant.revoked_at)}` : 'Revoked') : grant.expires_at ? shortDate(grant.expires_at) : <span className="muted">Never</span>}</td>
            <td>
                <StatusChip tone={status.tone} icon={status.icon}>
                    {status.label}
                </StatusChip>
            </td>
            <td>
                {grant.status !== 'revoked' && (
                    <div className="row" style={{ gap: 8, justifyContent: 'flex-end' }}>
                        <button type="button" className="btn btn-sm" style={{ minHeight: 44 }} onClick={onEdit} aria-label={`Edit access for ${name}`}>
                            Edit
                        </button>
                        <button type="button" className="btn btn-sm btn-danger" style={{ minHeight: 44 }} onClick={onRevoke} aria-label={`Revoke access for ${name}`} data-revoke>
                            Revoke
                        </button>
                    </div>
                )}
            </td>
        </tr>
    );
}

/**
 * Grant access (find the organization first) or edit a grant: which instances, how many seats,
 * until when, and a private note.
 */
export function GrantDialog({ extension, grant = null, onClose }) {
    const toast = useToast();
    const creating = !grant;
    const [draft, setDraft] = useState(() => grantDraft(grant));
    const [idsText, setIdsText] = useState(() => (grant?.instances ?? []).map((instance) => instance.id).join('\n'));
    // The organization found by the lookup: {id, slug, name, instances: [...] | null}.
    const [found, setFound] = useState(null);
    const [lookup, setLookup] = useState({ busy: false, error: null });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const set = (field) => (value) => setDraft((d) => ({ ...d, [field]: value }));

    async function find(identifier) {
        const value = String(identifier ?? '').trim();
        if (!value) {
            return;
        }
        setLookup({ busy: true, error: null });
        try {
            const organization = await api.get(`/publisher/access/organizations/${encodeURIComponent(value)}`);
            setFound(organization);
            setLookup({ busy: false, error: null });
        } catch (e) {
            setFound(null);
            setLookup({ busy: false, error: e.status === 404 ? 'No organization has that slug or ID.' : e.message });
        }
    }

    // Editing: look the organization up for its instance list (null: enter ids instead).
    useEffect(() => {
        if (grant?.organization) {
            find(grant.organization.slug ?? grant.organization.id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Instances the publisher can pick from: the organization's active ones, plus any the grant
    // already names (a revoked instance stays visible so it can be unticked).
    const listed = Array.isArray(found?.instances) ? [...found.instances] : null;
    if (listed) {
        for (const instance of grant?.instances ?? []) {
            if (!listed.some((item) => item.id === instance.id)) listed.push(instance);
        }
    }
    const pickable = listed !== null && listed.length > 0;
    const chosen = draft.scope !== 'some' ? [] : pickable ? draft.instances : parseInstanceIds(idsText);
    const seatsInvalid = Number.isNaN(parseSeats(draft.seats));
    const ready = (creating ? Boolean(found) : true) && !seatsInvalid && (draft.scope === 'all' || chosen.length > 0);

    function toggle(id) {
        set('instances')(draft.instances.includes(id) ? draft.instances.filter((value) => value !== id) : [...draft.instances, id]);
    }

    async function submit(event) {
        event.preventDefault();
        if (!ready || busy) {
            return;
        }
        setBusy(true);
        setError(null);
        const values = { ...draft, organization: found?.slug ?? draft.organization, instances: chosen };
        try {
            if (creating) {
                await api.post(`/publisher/extensions/${extension.slug}/access`, grantBody(values));
                toast(`Access granted to ${found.name}.`);
            } else {
                await api.patch(`/publisher/extensions/${extension.slug}/access/${grant.id}`, grantBody(values, { create: false }));
                toast(`Access updated for ${grant.organization?.name ?? 'the organization'}.`);
            }
            router.reload({ only: ['access'] });
            onClose();
        } catch (e) {
            setError(e);
            setBusy(false);
        }
    }

    const fieldError = (key) => error?.field?.(key) ?? null;
    const general = error && !Object.keys(error.fields ?? {}).length ? error.message : null;
    const title = creating ? `Grant access to ${extension.name}` : `Edit access for ${grant.organization?.name ?? 'the organization'}`;

    return (
        <Dialog
            open
            title={title}
            labelledBy="grant-dialog-title"
            onClose={onClose}
            size={560}
            footer={
                <>
                    <button type="button" className="btn" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="submit" form="grant-form" className="btn btn-primary" disabled={!ready || busy} aria-busy={busy || undefined} data-submit-grant>
                        {busy && <Icon name="spinner" spin />}
                        {creating ? 'Grant access' : 'Save'}
                    </button>
                </>
            }
        >
            <form id="grant-form" className="stack" onSubmit={submit} noValidate>
                {general && (
                    <Note tone="fail" role="alert" data-grant-error>
                        <span className="small">{general}</span>
                    </Note>
                )}

                {creating ? (
                    <div className="stack" style={{ gap: 8 }}>
                        <Field label="Organization" help="Its slug, as in /publishers/acme, or its ID (org_…)." error={lookup.error ?? fieldError('organization')}>
                            {(id, aria) => (
                                <div className="row" style={{ gap: 8 }}>
                                    <input
                                        id={id}
                                        {...aria}
                                        className="input grow"
                                        value={draft.organization}
                                        onChange={(e) => {
                                            // Another organization: its instances are not the ones picked so far.
                                            const value = e.target.value;
                                            setDraft((d) => ({ ...d, organization: value, instances: [] }));
                                            setFound(null);
                                            setLookup({ busy: false, error: null });
                                        }}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                find(draft.organization);
                                            }
                                        }}
                                        placeholder="acme"
                                        autoComplete="off"
                                        spellCheck={false}
                                        data-autofocus
                                    />
                                    <button type="button" className="btn" onClick={() => find(draft.organization)} disabled={!draft.organization.trim() || lookup.busy} aria-busy={lookup.busy || undefined}>
                                        {lookup.busy ? <Icon name="spinner" spin /> : <Icon name="search" />}
                                        Find
                                    </button>
                                </div>
                            )}
                        </Field>
                        {found && (
                            <div className="sd-pkg" style={{ fontFamily: 'var(--font)' }} data-found-organization={found.slug}>
                                <Icon name="building" />
                                <span className="grow">
                                    <strong>{found.name}</strong> <span className="muted">@{found.slug}</span>
                                </span>
                                <StatusChip tone="ok" icon="check">
                                    Found
                                </StatusChip>
                            </div>
                        )}
                    </div>
                ) : (
                    <p className="ink2">
                        {grant.organization?.name} <span className="muted">@{grant.organization?.slug}</span>
                    </p>
                )}

                {(found || !creating) && (
                    <>
                        <div className="field">
                            <span className="label" id="grant-scope-label">
                                Instances
                            </span>
                            <Segmented
                                role="radio"
                                label="Instances"
                                value={draft.scope}
                                onChange={set('scope')}
                                options={[
                                    { value: 'all', label: 'All instances' },
                                    { value: 'some', label: 'Only some' },
                                ]}
                            />
                            <span className="help">{draft.scope === 'all' ? 'Every instance of the organization, including ones it adds later.' : 'Only the instances you choose can install it.'}</span>
                        </div>

                        {draft.scope === 'some' &&
                            (pickable ? (
                                <fieldset className="stack" style={{ border: 0, margin: 0, padding: 0, gap: 6 }} data-instance-picker>
                                    <legend className="sr-only">Choose instances</legend>
                                    {listed.map((instance) => (
                                        <label key={instance.id} className="sd-slot" style={{ cursor: 'pointer' }}>
                                            <span className="row" style={{ gap: 10, minWidth: 0 }}>
                                                <input type="checkbox" checked={draft.instances.includes(instance.id)} onChange={() => toggle(instance.id)} />
                                                <span className="col" style={{ gap: 0, minWidth: 0 }}>
                                                    <span className="small">{instanceLabel(instance)}</span>
                                                    <span className="xs muted mono" style={{ overflowWrap: 'anywhere' }}>
                                                        {[instance.host, instance.id].filter(Boolean).join(' · ')}
                                                    </span>
                                                </span>
                                            </span>
                                            <span className="tag">{instance.kind === 'cloud' ? 'Cloud' : 'Self-hosted'}</span>
                                        </label>
                                    ))}
                                    {instancesError(error) && <span className="err">{instancesError(error)}</span>}
                                </fieldset>
                            ) : (
                                <Field
                                    label="Instance IDs"
                                    help={listed ? 'This organization has no active instances yet. Enter IDs (inst_…), one per line.' : 'Ask the organization for its instance IDs (inst_…). One per line, or separated by commas.'}
                                    error={instancesError(error)}
                                >
                                    {(id, aria) => <textarea id={id} {...aria} className="textarea mono" style={{ minHeight: 88 }} value={idsText} onChange={(e) => setIdsText(e.target.value)} placeholder="inst_…" spellCheck={false} />}
                                </Field>
                            ))}

                        <div className="grid2">
                            <Field label="Seats" optional help={seatsInvalid ? undefined : 'How many instances can use it at once. Empty: no limit.'} error={seatsInvalid ? 'A whole number, 1 or more.' : fieldError('max_activations')}>
                                {(id, aria) => <input id={id} {...aria} className="input num" inputMode="numeric" value={draft.seats} onChange={(e) => set('seats')(e.target.value)} placeholder="No limit" autoComplete="off" />}
                            </Field>
                            <Field label="Expires" optional help="Access stops on this date. Empty: it never expires." error={fieldError('expires_at')}>
                                {(id, aria) => <input id={id} {...aria} className="input" type="date" min={tomorrow()} value={draft.expires} onChange={(e) => set('expires')(e.target.value)} />}
                            </Field>
                        </div>

                        <Field label="Note" optional help="Only your organization’s owners and admins see it." error={fieldError('note')}>
                            {(id, aria) => <textarea id={id} {...aria} className="textarea" style={{ minHeight: 64 }} maxLength={500} value={draft.note} onChange={(e) => set('note')(e.target.value)} placeholder="Contract, ticket or contact" />}
                        </Field>
                    </>
                )}
            </form>
        </Dialog>
    );
}
