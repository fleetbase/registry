import { useState } from 'react';
import { router } from '@inertiajs/react';
import Dialog from '../Dialog';
import { Field, Note } from '../Ui';
import { useToast } from '../Toasts';
import { api } from '../../lib/api';

/** Which actions a publisher can take on a release in its current state. */
export function releaseActions(release) {
    const status = release?.status;
    return {
        submit: status === 'uploaded' || status === 'rejected',
        withdraw: ['submitted', 'in_review', 'approved'].includes(status) || (status === 'checking' && Boolean(release?.submission?.requested)),
        yank: status === 'published',
    };
}

const COPY = {
    submit: {
        title: (name, v) => `Submit ${name} v${v}?`,
        body: 'Fleetbase reviews the release and the listing as they are now. You can withdraw at any time before a decision.',
        button: 'Submit for review',
        busy: 'Submitting…',
        tone: 'btn-primary',
    },
    withdraw: {
        title: (name, v) => `Withdraw ${name} v${v} from review?`,
        body: 'It goes back to “Ready to submit”. Submitting again puts it at the back of the queue.',
        button: 'Withdraw',
        busy: 'Withdrawing…',
        tone: 'btn-danger-solid',
        reason: true,
    },
    yank: {
        title: (name, v) => `Yank ${name} v${v}?`,
        body: 'It disappears from the catalog and new installs. Lockfiles and instances that already have it keep working. This cannot be undone; publish a new version instead.',
        button: 'Yank release',
        busy: 'Yanking…',
        tone: 'btn-danger-solid',
        reason: true,
    },
};

/**
 * Confirm and run a release action (`POST /v1/publisher/releases/{id}/submit|withdraw|yank`),
 * then reload the page's props. A submit that is not ready shows the service's reasons.
 */
export default function ReleaseActionDialog({ action, release, extension, onClose, children }) {
    const toast = useToast();
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const copy = COPY[action] ?? COPY.submit;

    function close() {
        if (!busy) {
            setError(null);
            setReason('');
            onClose();
        }
    }

    async function run() {
        setBusy(true);
        setError(null);
        try {
            const result = await api.post(`/publisher/releases/${release.id}/${action}`, copy.reason && reason.trim() ? { reason: reason.trim() } : {});
            let message = { submit: 'Submitted for review.', withdraw: 'Withdrawn from review.', yank: `v${release.version} yanked.` }[action];
            if (action === 'submit' && result?.status === 'checking') {
                message = 'Checks are still running. It is submitted as soon as they pass.';
            } else if (action === 'submit' && result?.status === 'published') {
                message = `v${release.version} is live. Nothing in it needed a person to review.`;
            } else if (action === 'withdraw' && result?.status === 'checking') {
                message = 'It will not be submitted when the checks finish.';
            }
            setBusy(false);
            setReason('');
            onClose();
            toast(message);
            router.reload();
        } catch (e) {
            setError(e);
            setBusy(false);
        }
    }

    const reasons = error?.fields?.release ?? (error ? error.errors : []);

    return (
        <Dialog
            open={Boolean(action && release)}
            title={release ? copy.title(extension.name, release.version) : ''}
            labelledBy={`release-${action}-title`}
            onClose={close}
            dismissible={!busy}
            footer={
                <>
                    <button type="button" className="btn" onClick={close} disabled={busy}>
                        Not yet
                    </button>
                    <button type="button" className={`btn ${copy.tone}`} onClick={run} disabled={busy} aria-busy={busy || undefined} data-confirm={action}>
                        {busy ? copy.busy : copy.button}
                    </button>
                </>
            }
        >
            <div className="stack">
                <p className="ink2">{copy.body}</p>
                {children}
                {copy.reason && (
                    <Field label="Reason" optional help="Recorded in the release’s history.">
                        {(id, aria) => <textarea id={id} {...aria} className="textarea" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={2000} style={{ minHeight: 72 }} />}
                    </Field>
                )}
                {error && (
                    <Note tone="fail" role="alert" data-action-error>
                        <strong>{action === 'submit' ? 'Not submitted yet' : 'That did not work'}</strong>
                        {reasons.length > 1 || action === 'submit' ? (
                            <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                                {reasons.map((message) => (
                                    <li key={message} className="small">
                                        {message}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="small">{reasons[0]}</div>
                        )}
                    </Note>
                )}
            </div>
        </Dialog>
    );
}
