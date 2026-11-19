import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Dialog from '../Dialog';
import Icon from '../Icon';
import { useToast } from '../Toasts';
import { Field, Note } from '../Ui';
import { api } from '../../lib/api';
import { shortDate, timeAgo } from '../../lib/format';
import { workflowSnippet } from './access';

/** At most this many per extension (TrustedPublisherController::MAX_PER_EXTENSION). */
export const MAX_TRUSTED_PUBLISHERS = 10;

const EMPTY = { repository: '', workflow: 'release.yml', environment: '', refs: 'refs/tags/v*' };

/** `refs/tags/v*, refs/heads/main` → `['refs/tags/v*', 'refs/heads/main']`. */
export function parseRefs(text) {
    return [
        ...new Set(
            String(text ?? '')
                .split(/[\s,]+/)
                .map((ref) => ref.trim())
                .filter(Boolean),
        ),
    ];
}

/** The first validation message for `refs` or any `refs.N`. */
function refsError(error) {
    const key = Object.keys(error?.fields ?? {}).find((name) => name === 'refs' || name.startsWith('refs.'));
    return key ? error.field(key) : null;
}

/**
 * Trusted publishing: GitHub Actions workflows that publish releases with a short-lived OIDC
 * token instead of a stored one. The list, a form to add one, and the workflow to copy.
 */
export default function TrustedPublishersSection({ extension, publishers = [] }) {
    const toast = useToast();
    const [adding, setAdding] = useState(publishers.length === 0);
    const [form, setForm] = useState(EMPTY);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [removeBusy, setRemoveBusy] = useState(false);
    const full = publishers.length >= MAX_TRUSTED_PUBLISHERS;

    const set = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }));
    const refs = parseRefs(form.refs);
    const registryUrl = usePage().props.registry?.url || '';
    const sample = adding || !publishers.length ? { workflow: form.workflow.trim(), refs, environment: form.environment.trim() } : publishers[0];

    async function add(event) {
        event.preventDefault();
        if (busy) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const created = await api.post(`/publisher/extensions/${extension.slug}/trusted-publishers`, {
                repository: form.repository.trim(),
                workflow: form.workflow.trim(),
                environment: form.environment.trim() || null,
                refs,
            });
            toast(`${created.workflow} in ${created.repository} can now publish ${extension.name}.`);
            setForm(EMPTY);
            setAdding(false);
            router.reload({ only: ['trusted_publishers'] });
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    async function remove() {
        setRemoveBusy(true);
        try {
            await api.delete(`/publisher/extensions/${extension.slug}/trusted-publishers/${removing.id}`);
            toast(`${removing.repository} can no longer publish ${extension.name}.`);
            setRemoving(null);
            router.reload({ only: ['trusted_publishers'] });
        } catch (e) {
            toast(e.message, { type: 'error' });
        } finally {
            setRemoveBusy(false);
        }
    }

    const fieldError = (key) => error?.field?.(key) ?? null;
    const general = error && !Object.keys(error.fields ?? {}).length ? error.message : null;

    return (
        <section id="s-trusted" className="card" aria-labelledby="h-trusted" data-trusted-publishers>
            <div className="card-head">
                <div className="col" style={{ gap: 2 }}>
                    <h2 id="h-trusted" className="h3">
                        Trusted publishing
                    </h2>
                    <span className="small muted">Publish from GitHub Actions without storing a token</span>
                </div>
                {!adding && (
                    <button type="button" className="btn btn-sm" onClick={() => setAdding(true)} disabled={full} data-add-trusted>
                        <Icon name="plus" />
                        Add a workflow
                    </button>
                )}
            </div>

            {publishers.length > 0 && (
                <ul className="card-body col" style={{ gap: 10, listStyle: 'none', margin: 0 }}>
                    {publishers.map((publisher) => (
                        <li key={publisher.id} className="sd-slot" data-trusted-publisher={publisher.id}>
                            <div className="col" style={{ gap: 6, minWidth: 0 }}>
                                <span className="row wrapflex" style={{ gap: 8 }}>
                                    <Icon name="terminal" />
                                    <span className="mono" style={{ overflowWrap: 'anywhere' }}>
                                        {publisher.repository}
                                    </span>
                                    <span className="muted">·</span>
                                    <span className="mono small">{publisher.workflow}</span>
                                    {publisher.environment && <span className="tag">env: {publisher.environment}</span>}
                                </span>
                                <span className="row wrapflex" style={{ gap: 6 }}>
                                    {(publisher.refs ?? []).map((ref) => (
                                        <span key={ref} className="tag mono">
                                            {ref}
                                        </span>
                                    ))}
                                </span>
                                <span className="xs muted">
                                    {publisher.last_used_at ? `Last published ${timeAgo(publisher.last_used_at)}` : 'Not used yet'}
                                    {publisher.created_by?.name ? ` · added by ${publisher.created_by.name}` : ''}
                                    {publisher.created_at ? ` on ${shortDate(publisher.created_at)}` : ''}
                                </span>
                            </div>
                            <button type="button" className="btn btn-sm btn-danger" style={{ minHeight: 44 }} onClick={() => setRemoving(publisher)} aria-label={`Remove ${publisher.repository} ${publisher.workflow}`} data-remove-trusted>
                                Remove
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="card-body stack">
                {full && !adding && <span className="small muted">An extension can have at most {MAX_TRUSTED_PUBLISHERS} trusted publishers. Remove one to add another.</span>}

                {adding && (
                    <form className="stack" onSubmit={add} noValidate aria-label="Add a trusted publisher" data-trusted-form>
                        {general && (
                            <Note tone="fail" role="alert">
                                <span className="small">{general}</span>
                            </Note>
                        )}
                        <div className="grid2">
                            <Field label="Repository" help="The GitHub repository, owner/name." error={fieldError('repository')}>
                                {(id, aria) => <input id={id} {...aria} className="input mono" value={form.repository} onChange={set('repository')} placeholder="acme/label-printer" autoComplete="off" spellCheck={false} />}
                            </Field>
                            <Field label="Workflow file" help="The file name under .github/workflows." error={fieldError('workflow')}>
                                {(id, aria) => <input id={id} {...aria} className="input mono" value={form.workflow} onChange={set('workflow')} placeholder="release.yml" autoComplete="off" spellCheck={false} />}
                            </Field>
                            <Field label="Environment" optional help="Only runs in this GitHub environment can publish." error={fieldError('environment')}>
                                {(id, aria) => <input id={id} {...aria} className="input mono" value={form.environment} onChange={set('environment')} placeholder="release" autoComplete="off" spellCheck={false} />}
                            </Field>
                            <Field label="Refs" help="Git refs that may publish, separated by commas. * matches anything." error={refsError(error)}>
                                {(id, aria) => <input id={id} {...aria} className="input mono" value={form.refs} onChange={set('refs')} placeholder="refs/tags/v*" autoComplete="off" spellCheck={false} />}
                            </Field>
                        </div>
                        <div className="row wrapflex between">
                            <span className="small muted">Releases from the workflow are published as you, so keep your publishing role in {extension.organization?.name ?? 'the organization'}.</span>
                            <div className="row" style={{ gap: 8 }}>
                                {publishers.length > 0 && (
                                    <button
                                        type="button"
                                        className="btn"
                                        onClick={() => {
                                            setAdding(false);
                                            setError(null);
                                            setForm(EMPTY);
                                        }}
                                    >
                                        Cancel
                                    </button>
                                )}
                                <button type="submit" className="btn btn-primary" disabled={busy || !form.repository.trim() || !form.workflow.trim() || refs.length === 0} aria-busy={busy || undefined}>
                                    {busy && <Icon name="spinner" spin />}
                                    Add trusted publisher
                                </button>
                            </div>
                        </div>
                    </form>
                )}

                <div className="stack" style={{ gap: 8 }}>
                    <span className="label">Workflow</span>
                    <CopySnippet value={workflowSnippet({ ...sample, registry: registryUrl })} label="workflow" />
                    <span className="small muted">
                        <code>id-token: write</code> lets the job ask GitHub for a token the registry trusts; nothing is stored in the repository. Releases still run every automated check.
                    </span>
                </div>
            </div>

            <Dialog
                open={Boolean(removing)}
                title={`Remove ${removing?.repository ?? 'this workflow'}?`}
                labelledBy="remove-trusted-title"
                onClose={() => setRemoving(null)}
                footer={
                    <>
                        <button type="button" className="btn" onClick={() => setRemoving(null)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger-solid" onClick={remove} disabled={removeBusy} data-confirm-remove>
                            {removeBusy && <Icon name="spinner" spin />}
                            Remove
                        </button>
                    </>
                }
            >
                <p className="ink2">
                    <code>{removing?.workflow}</code> can no longer publish {extension.name}, and publish tokens it was given stop working straight away. Releases it already published stay.
                </p>
            </Dialog>
        </section>
    );
}

/** A multi-line snippet with a copy button; the label reads "Copied" for 1.5 s. */
export function CopySnippet({ value, label = 'snippet' }) {
    const [copied, setCopied] = useState(false);
    const timer = useRef();
    useEffect(() => () => clearTimeout(timer.current), []);

    async function copy() {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            clearTimeout(timer.current);
            timer.current = setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard access can be refused; the snippet stays selectable.
        }
    }

    return (
        <div style={{ position: 'relative' }}>
            <pre className="pre" tabIndex={0} aria-label={label} data-snippet>
                {value}
            </pre>
            <div className="cmd" style={{ position: 'absolute', top: 6, right: 6, minHeight: 0, padding: 0, background: 'transparent' }}>
                <button type="button" className={`copy ${copied ? 'done' : ''}`} onClick={copy} aria-label={copied ? `Copied ${label}` : `Copy ${label}`}>
                    <Icon name={copied ? 'check' : 'copy'} size={14} strokeWidth={copied ? 2.6 : 2} />
                    <span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span>
                </button>
            </div>
        </div>
    );
}
