import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Field, SafeHtml } from '../Ui';
import { useToast } from '../Toasts';
import { api } from '../../lib/api';

export const NOTES_MAX = 20000;

/**
 * The release notes card, with an inline editor. Notes save straight away and never need
 * review; a yanked release's notes are final.
 */
export default function ReleaseNotes({ release }) {
    const toast = useToast();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const editButton = useRef(null);
    const textarea = useRef(null);
    const returnFocus = useRef(false);
    const editable = release.status !== 'yanked';

    useEffect(() => {
        if (editing) {
            textarea.current?.focus();
        } else if (returnFocus.current) {
            returnFocus.current = false;
            editButton.current?.focus();
        }
    }, [editing]);

    function start() {
        setDraft(release.notes ?? '');
        setError(null);
        setEditing(true);
    }

    function cancel() {
        returnFocus.current = true;
        setEditing(false);
        setError(null);
    }

    async function save(event) {
        event.preventDefault();
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            await api.patch(`/publisher/releases/${release.id}`, { notes: draft.trim() === '' ? null : draft });
            await new Promise((resolve) => router.reload({ only: ['release'], onFinish: resolve }));
            returnFocus.current = true;
            setEditing(false);
            toast('Release notes saved.');
        } catch (e) {
            const message = e.field?.('notes');
            if (message) {
                setError(message);
            } else {
                toast(e.message, { type: 'error' });
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="card card-pad stack" aria-labelledby="h-notes" data-release-notes>
            <h2 id="h-notes" className="h4">
                Release notes
            </h2>
            {editing ? (
                <form method="post" className="stack" onSubmit={save} noValidate>
                    <Field label="Notes for this version" help="Shown with this version in the catalog. Saving needs no review." error={error}>
                        {(id, aria) => (
                            <textarea
                                ref={textarea}
                                id={id}
                                {...aria}
                                className="textarea"
                                rows={6}
                                maxLength={NOTES_MAX}
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Escape') {
                                        e.stopPropagation();
                                        cancel();
                                    }
                                }}
                            />
                        )}
                    </Field>
                    <div className="row wrapflex" style={{ gap: 8 }}>
                        <button type="submit" className="btn btn-primary btn-sm" disabled={busy} aria-busy={busy || undefined}>
                            {busy ? 'Saving…' : 'Save notes'}
                        </button>
                        <button type="button" className="btn btn-sm" onClick={cancel} disabled={busy}>
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <>
                    {release.notes ? <SafeHtml html={release.notes} className="small ink2" /> : <p className="small muted">No notes for this release.</p>}
                    {editable && (
                        <button ref={editButton} type="button" className="sd-linkbtn small" style={{ alignSelf: 'flex-start', minHeight: 44 }} onClick={start}>
                            {release.notes ? 'Edit notes' : 'Add notes'}
                        </button>
                    )}
                </>
            )}
        </section>
    );
}
