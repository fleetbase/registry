import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import Dialog from '../Dialog';
import { checksSummary } from '../Checks';
import { StatusChip } from '../Status';
import { useToast } from '../Toasts';
import { Field } from '../Ui';
import { api } from '../../lib/api';

const SELECTED = { background: 'var(--surface)', color: 'var(--ink)', boxShadow: 'var(--e1), 0 0 0 1px var(--line)' };

/** The console's sections: Waiting, To publish, Decided, Failed checks. */
export function ReviewTabs({ current, counts = {} }) {
    const tabs = [
        { key: 'waiting', href: '/review', label: `Waiting · ${counts.waiting ?? 0}` },
        { key: 'publish', href: '/review/publish', label: `To publish · ${counts.publish ?? 0}` },
        { key: 'decided', href: '/review?tab=decided', label: 'Decided' },
        { key: 'failed', href: '/review?tab=failed', label: 'Failed checks' },
    ];
    return (
        <nav className="seg" aria-label="Queue" style={{ flexWrap: 'wrap' }}>
            {tabs.map((tab) => (
                <Link key={tab.key} href={tab.href} aria-current={tab.key === current ? 'page' : undefined} data-tab={tab.key} style={{ minHeight: 44, ...(tab.key === current ? SELECTED : {}) }}>
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}

const SUMMARY_ICONS = { ok: 'check', fail: 'x', wait: 'spinner', idle: 'minus', you: 'alert' };

/** The checks summary as a status chip (warnings read as amber). */
export function ChecksChip({ release }) {
    const summary = checksSummary(release);
    const tone = summary.tone === 'ok' && /warning/.test(summary.label) ? 'you' : summary.tone;
    return (
        <StatusChip tone={tone} icon={SUMMARY_ICONS[tone]} data-checks={summary.tone}>
            {summary.label}
        </StatusChip>
    );
}

/** Runs a /v1 write, toasts the outcome and reloads the page's props. */
export function useAction() {
    const toast = useToast();
    const [busy, setBusy] = useState(false);

    async function run(request, { success, action, onDone, onError } = {}) {
        setBusy(true);
        try {
            const result = await request();
            toast(typeof success === 'function' ? success(result) : success, { type: 'ok', action: typeof action === 'function' ? action(result) : action });
            onDone?.(result);
            router.reload();
            return result;
        } catch (error) {
            if (onError) {
                onError(error);
            } else {
                toast(error.message, { type: 'error' });
            }
            return null;
        } finally {
            setBusy(false);
        }
    }

    return { run, busy };
}

/** Suspend a listing: a reason is required, and the publisher sees it. */
export function SuspendDialog({ open, extension, onClose }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState(null);
    const { run, busy } = useAction();
    const ready = reason.trim().length >= 3;

    function submit() {
        if (!ready || busy) {
            setError(ready ? null : 'Say what is wrong and what would lift the suspension.');
            return;
        }
        run(() => api.post(`/admin/extensions/${extension.slug}/suspend`, { reason: reason.trim() }), {
            success: `${extension.name} is suspended.`,
            onDone: () => {
                setReason('');
                onClose();
            },
            onError: (e) => setError(e.field('reason') ?? e.message),
        });
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`Suspend ${extension?.name}?`}
            labelledBy="suspend-title"
            footer={
                <>
                    <button type="button" className="btn" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-danger-solid" aria-disabled={!ready || busy} onClick={submit} data-confirm-suspend>
                        {busy ? 'Suspending…' : 'Suspend listing'}
                    </button>
                </>
            }
        >
            <p className="ink2">It leaves the catalog and the npm and Composer endpoints at once. Uploads and publishing stop. Instances that installed it keep their copy.</p>
            <Field label="Reason shown to the publisher" error={error}>
                {(id, aria) => <textarea id={id} {...aria} className="textarea" data-autofocus placeholder="What is wrong and what would lift it" value={reason} onChange={(event) => setReason(event.target.value)} />}
            </Field>
        </Dialog>
    );
}

/** Lift a suspension: the listing returns to the status it had. */
export function LiftDialog({ open, extension, onClose }) {
    const [reason, setReason] = useState('');
    const { run, busy } = useAction();

    function submit() {
        if (busy) {
            return;
        }
        run(() => api.post(`/admin/extensions/${extension.slug}/unsuspend`, reason.trim() ? { reason: reason.trim() } : {}), {
            success: `${extension.name} is back in the catalog.`,
            onDone: () => {
                setReason('');
                onClose();
            },
        });
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`Lift the suspension of ${extension?.name}?`}
            labelledBy="lift-title"
            footer={
                <>
                    <button type="button" className="btn" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-primary" aria-disabled={busy} onClick={submit} data-confirm-lift>
                        {busy ? 'Lifting…' : 'Lift suspension'}
                    </button>
                </>
            }
        >
            <p className="ink2">The listing goes back to the status it had before. If it was published, it returns to the catalog and the package endpoints.</p>
            <Field label="Note for the record" optional>
                {(id, aria) => <textarea id={id} {...aria} className="textarea" value={reason} onChange={(event) => setReason(event.target.value)} />}
            </Field>
        </Dialog>
    );
}

/** The toast link to the live listing after publishing. */
export function ViewListing({ slug }) {
    return (
        <Link href={`/extensions/${slug}`} style={{ color: 'inherit', fontWeight: 600, textDecoration: 'underline' }}>
            View
        </Link>
    );
}

/** Publish an approved release after a confirmation. */
export function PublishDialog({ open, release, onClose }) {
    const { run, busy } = useAction();
    const name = release?.extension?.name ?? 'This release';

    function submit() {
        if (busy) {
            return;
        }
        run(() => api.post(`/admin/releases/${release.id}/publish`), {
            success: `${name} v${release.version} is live. Instances see it within a minute.`,
            action: release.extension?.slug ? <ViewListing slug={release.extension.slug} /> : null,
            onDone: onClose,
        });
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`Publish ${name} v${release?.version}?`}
            labelledBy="publish-title"
            footer={
                <>
                    <button type="button" className="btn" onClick={onClose}>
                        Cancel
                    </button>
                    <button type="button" className="btn btn-primary" aria-disabled={busy} onClick={submit} data-confirm-publish>
                        {busy ? 'Publishing…' : 'Publish'}
                    </button>
                </>
            }
        >
            <p className="ink2">It becomes the latest version in the catalog, npm and Composer, and the publisher is emailed.</p>
        </Dialog>
    );
}
