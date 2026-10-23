import { useMemo, useRef, useState } from 'react';
import Dialog from '../Dialog';
import Icon from '../Icon';
import { ReleaseStatus } from '../Status';
import { Field, Note } from '../Ui';
import { api } from '../../lib/api';
import { dateTime } from '../../lib/format';
import { autoPassed, decisionItems, decisionState, initialMarks, toggleMark } from './decision';
import { ViewListing, useAction } from './Parts';

/** The reviewer's working state for one release: marks, notes, message, publish-at-once. */
export function useDecision(release, items) {
    const [marks, setMarks] = useState(() => initialMarks(release, items));
    const [notes, setNotes] = useState({});
    const [reason, setReason] = useState('');
    const [publishNow, setPublishNow] = useState(true);
    const [confirm, setConfirm] = useState(null);
    const auto = useMemo(() => new Set(autoPassed(release)), [release]);
    const state = decisionState({ items, marks, reason, publishNow });
    const cardRef = useRef(null);

    return {
        release,
        items,
        marks,
        notes,
        reason,
        publishNow,
        confirm,
        auto,
        state,
        cardRef,
        mark: (code, value) => setMarks((current) => toggleMark(current, code, value)),
        setNote: (code, value) => setNotes((current) => ({ ...current, [code]: value })),
        setReason,
        setPublishNow,
        setConfirm,
        /** Ask to confirm, or point at the rail when the action is not allowed yet. */
        request(kind) {
            const allowed = kind === 'approve' ? state.canApprove : state.canReject;
            if (allowed) {
                setConfirm(kind);
                return;
            }
            cardRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
            cardRef.current?.querySelector('[data-decision-hint]')?.focus?.();
        },
    };
}

const PF_BASE = { minHeight: 44, minWidth: 64, padding: '0 10px', borderRadius: 8, border: '1px solid var(--line-strong)', background: 'var(--surface)', color: 'var(--ink-2)', font: '600 13px/1 var(--font)', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 4 };
const PF_ON = {
    pass: { background: 'var(--ok-bg)', borderColor: 'var(--ok-line)', color: 'var(--ok)' },
    fail: { background: 'var(--fail-bg)', borderColor: 'var(--fail-line)', color: 'var(--fail)' },
};

/** The requirements card: Pass / Fail per R-item with a note, the message, and the two decisions. */
export function DecisionCard({ decision }) {
    const { items, marks, notes, reason, publishNow, auto, state, cardRef } = decision;

    return (
        <section ref={cardRef} className="card" style={{ boxShadow: 'var(--e2)' }} aria-labelledby="decision-title" data-decision>
            <div className="card-head">
                <h2 id="decision-title" className="h4">
                    Requirements
                </h2>
                <span className="small muted" data-progress>
                    {state.progress}
                </span>
            </div>
            <div className="card-body" style={{ paddingTop: 4, paddingBottom: 4 }}>
                {items.map((item, index) => (
                    <div key={item.code} className="col" style={{ gap: 8, padding: '14px 0', borderTop: index ? '1px solid var(--line)' : 0 }} data-item={item.code} data-mark={marks[item.code] ?? 'none'}>
                        <div className="row between" style={{ gap: 10 }}>
                            <span className="small">
                                <strong className="mono">{item.code}</strong> · {item.label}
                            </span>
                            {auto.has(item.code) && (
                                <span style={{ fontSize: 11, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '.06em' }} data-auto>
                                    from checks
                                </span>
                            )}
                        </div>
                        <div className="row wrapflex" style={{ gap: 8 }}>
                            <div role="group" aria-label={`${item.code} result`} style={{ display: 'inline-flex', gap: 4 }}>
                                {[
                                    ['pass', 'check', 'Pass'],
                                    ['fail', 'x', 'Fail'],
                                ].map(([value, icon, label]) => (
                                    <button key={value} type="button" aria-pressed={marks[item.code] === value} onClick={() => decision.mark(item.code, value)} style={{ ...PF_BASE, ...(marks[item.code] === value ? PF_ON[value] : {}) }}>
                                        <Icon name={icon} size={13} strokeWidth={3} />
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <input
                                className="input grow"
                                style={{ minHeight: 44, padding: '6px 10px', fontSize: 13, minWidth: 160 }}
                                aria-label={`Note for ${item.code}, shown to the publisher`}
                                placeholder="Note for the publisher"
                                value={notes[item.code] ?? ''}
                                onChange={(event) => decision.setNote(item.code, event.target.value)}
                            />
                        </div>
                    </div>
                ))}
            </div>
            <div className="card-body stack" style={{ borderTop: '1px solid var(--line)' }}>
                <Field label="Message to the publisher" optional={state.reasonHint}>
                    {(id, aria) => <textarea id={id} {...aria} className="textarea" placeholder="What to change, and why. They see this in Studio and by email." value={reason} onChange={(event) => decision.setReason(event.target.value)} />}
                </Field>
                <label className="check small">
                    <input type="checkbox" checked={publishNow} onChange={(event) => decision.setPublishNow(event.target.checked)} /> Publish as soon as it’s approved
                </label>
                <div className="row" style={{ gap: 10 }}>
                    <button type="button" className="btn btn-danger grow" aria-disabled={!state.canReject} onClick={() => decision.request('reject')} data-reject>
                        {state.rejectLabel}
                    </button>
                    <button type="button" className="btn btn-primary grow" aria-disabled={!state.canApprove} onClick={() => decision.request('approve')} data-approve>
                        {state.approveLabel}
                    </button>
                </div>
                <span className="xs muted" tabIndex={-1} aria-live="polite" data-decision-hint>
                    {state.hint}
                </span>
            </div>
        </section>
    );
}

/** Approve / Reject pinned to the bottom of the screen on phones. */
export function DecisionBar({ decision }) {
    const { state } = decision;
    return (
        <div className="show-sm" style={{ position: 'sticky', bottom: 0, gap: 8, padding: '12px 16px', background: 'var(--raised)', borderTop: '1px solid var(--line)', zIndex: 4 }} data-decision-bar>
            <button type="button" className="btn btn-danger" style={{ flex: 1 }} aria-disabled={!state.canReject} onClick={() => decision.request('reject')}>
                Reject
            </button>
            <button type="button" className="btn btn-primary" style={{ flex: 1 }} aria-disabled={!state.canApprove} onClick={() => decision.request('approve')}>
                Approve
            </button>
        </div>
    );
}

/** The confirmations for Approve and Request changes, and the requests behind them. */
export function DecisionDialogs({ decision }) {
    const { release, items, marks, notes, reason, publishNow, confirm, state } = decision;
    const { run, busy } = useAction();
    const name = `${release.extension?.name ?? 'The release'} v${release.version}`;
    const close = () => decision.setConfirm(null);
    const failedItems = items.filter((item) => marks[item.code] === 'fail');

    function approve() {
        if (busy) {
            return;
        }
        const message = reason.trim();
        run(() => api.post(`/admin/releases/${release.id}/approve`, { ...(message ? { reason: message } : {}), items: decisionItems(items, marks, notes), publish: publishNow }), {
            success: (result) => (result?.status === 'published' ? `${name} is live. Instances see it within a minute.` : `${name} is approved. It waits under To publish.`),
            action: (result) => (result?.status === 'published' && release.extension?.slug ? <ViewListing slug={release.extension.slug} /> : null),
            onDone: close,
        });
    }

    function reject() {
        if (busy) {
            return;
        }
        run(() => api.post(`/admin/releases/${release.id}/reject`, { reason: reason.trim(), items: decisionItems(items, marks, notes) }), {
            success: `Changes requested on ${name}. The publisher has been emailed.`,
            onDone: close,
        });
    }

    return (
        <>
            <Dialog
                open={confirm === 'approve'}
                onClose={close}
                title={publishNow ? `Approve and publish ${name}?` : `Approve ${name}?`}
                labelledBy="approve-title"
                footer={
                    <>
                        <button type="button" className="btn" onClick={close}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-primary" aria-disabled={busy} onClick={approve} data-confirm-approve>
                            {busy ? 'Approving…' : state.approveLabel}
                        </button>
                    </>
                }
            >
                <p className="ink2">
                    {publishNow
                        ? 'It becomes the latest version in the catalog, npm and Composer at once, and the publisher is emailed.'
                        : 'It waits under To publish until someone publishes it. The publisher sees it as approved.'}
                </p>
                <p className="small muted">All {items.length} requirements pass.</p>
            </Dialog>
            <Dialog
                open={confirm === 'reject'}
                onClose={close}
                title={`Request changes on ${name}?`}
                labelledBy="reject-title"
                footer={
                    <>
                        <button type="button" className="btn" onClick={close}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger-solid" aria-disabled={busy} onClick={reject} data-confirm-reject>
                            {busy ? 'Sending…' : 'Request changes'}
                        </button>
                    </>
                }
            >
                <p className="ink2">The publisher is emailed this message and the failed requirements. They fix and resubmit.</p>
                <div className="quote" style={{ whiteSpace: 'pre-line' }}>
                    {reason.trim()}
                </div>
                <ul className="col small" style={{ gap: 6, margin: 0, paddingLeft: 18 }}>
                    {failedItems.map((item) => (
                        <li key={item.code}>
                            <strong className="mono">{item.code}</strong> {item.label}
                            {notes[item.code]?.trim() && <span className="muted">: {notes[item.code].trim()}</span>}
                        </li>
                    ))}
                </ul>
            </Dialog>
        </>
    );
}

/** The rail once there is nothing to decide: the decision taken, or why there is none. */
export function DecidedCard({ release, itemLabels, onPublish }) {
    const decision = [...(release.reviews ?? [])].reverse().find((entry) => entry.action === 'approve' || entry.action === 'reject');
    const failed = (decision?.items ?? []).filter((item) => item.passed === false);
    const open = ['uploaded', 'checking', 'failed_checks'].includes(release.status);

    return (
        <section className="card card-pad stack" aria-labelledby="decided-title" data-decided>
            <div className="row between wrapflex" style={{ gap: 10 }}>
                <h2 id="decided-title" className="h4">
                    Decision
                </h2>
                <ReleaseStatus status={release.status} />
            </div>
            {open ? (
                <p className="small ink2">Nothing to decide: the release is not submitted for review.</p>
            ) : decision ? (
                <div className="col" style={{ gap: 6 }}>
                    <span className="small">
                        {decision.action === 'approve' ? 'Approved' : 'Changes requested'}
                        {decision.by?.name ? ` by ${decision.by.name}` : decision.actor === 'system' ? ' automatically' : ''} · {dateTime(decision.created_at)}
                    </span>
                    {decision.reason && (
                        <div className="quote" style={{ whiteSpace: 'pre-line', marginTop: 0 }}>
                            {decision.reason}
                        </div>
                    )}
                    {failed.length > 0 && (
                        <ul className="col small" style={{ gap: 6, margin: 0, paddingLeft: 18 }}>
                            {failed.map((item) => (
                                <li key={item.code}>
                                    <strong className="mono">{item.code}</strong> {itemLabels[item.code] ?? ''}
                                    {item.note && <span className="muted">: {item.note}</span>}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : (
                <p className="small ink2">No decision recorded.</p>
            )}
            {release.status === 'approved' && (
                <>
                    <Note tone="info">
                        <span className="small">Approved without publishing. Publishing makes it the latest version in the catalog, npm and Composer.</span>
                    </Note>
                    <button type="button" className="btn btn-primary" onClick={onPublish} data-publish>
                        <Icon name="globe" /> Publish
                    </button>
                </>
            )}
        </section>
    );
}
