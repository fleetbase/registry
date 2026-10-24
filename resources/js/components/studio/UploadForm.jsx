import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Icon from '../Icon';
import { Field, Note } from '../Ui';
import { api } from '../../lib/api';
import { bytes } from '../../lib/format';

export const SLOTS = [
    { key: 'manifest', label: 'Manifest', hint: 'extension.json', accept: '.json,application/json', required: true },
    { key: 'engine', label: 'Engine (npm)', hint: '.tgz from npm pack', accept: '.tgz,.gz,application/gzip' },
    { key: 'api', label: 'API (Composer)', hint: '.tar or .tar.gz', accept: '.tar,.gz,.tgz,application/x-tar,application/gzip' },
    { key: 'dist', label: 'Prebuilt engine', hint: 'optional · the dist folder, tarred', accept: '.tar,.gz,.tgz,application/x-tar,application/gzip', optional: true },
];

/** Which slot a dropped file fills, from its name. */
export function slotFor(name) {
    const file = String(name ?? '').toLowerCase();
    if (file.endsWith('.json')) return 'manifest';
    if (/dist/.test(file) && /\.(tar|tgz|tar\.gz)$/.test(file)) return 'dist';
    if (file.endsWith('.tgz')) return 'engine';
    if (file.endsWith('.tar') || file.endsWith('.tar.gz')) return 'api';
    return null;
}

/** The upload can go when there is a manifest and at least one of the engine and API archives. */
export function uploadReady(files) {
    return Boolean(files.manifest && (files.engine || files.api));
}

/**
 * Upload a release: drop the files (or pick each), choose the channel, add notes, optionally
 * submit when the checks pass. Goes to the release page, where the checks run live.
 */
export default function UploadForm({ extension }) {
    const [files, setFiles] = useState({});
    const [channel, setChannel] = useState('');
    const [notes, setNotes] = useState('');
    const [submit, setSubmit] = useState(false);
    const [over, setOver] = useState(false);
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState(null);
    const [unknown, setUnknown] = useState([]);
    const inputs = useRef({});
    const busy = progress !== null;
    const ready = uploadReady(files) && !busy;

    function drop(list) {
        const next = { ...files };
        const skipped = [];
        for (const file of list) {
            const slot = slotFor(file.name);
            if (slot) {
                next[slot] = file;
            } else {
                skipped.push(file.name);
            }
        }
        setFiles(next);
        setUnknown(skipped);
    }

    async function upload(event) {
        event.preventDefault();
        if (!ready) {
            return;
        }
        setError(null);
        setProgress(0);
        try {
            const release = await api.upload(`/publisher/extensions/${extension.slug}/releases`, { ...files, channel: channel || undefined, notes: notes.trim() || undefined, submit }, { onProgress: setProgress });
            router.visit(`/studio/${extension.slug}/releases/${release.id}`);
        } catch (e) {
            setError(e);
            setProgress(null);
        }
    }

    const fieldError = (key) => error?.field?.(key) ?? null;
    const general = error && !Object.keys(error.fields ?? {}).length ? error.message : null;

    return (
        <form method="post" className="card" aria-labelledby="h-upload" onSubmit={upload} noValidate data-upload-form>
            <div className="card-head">
                <h2 id="h-upload" className="h3">
                    Upload a release
                </h2>
                <span className="small muted">Eight checks run at once</span>
            </div>
            <div className="card-body stack">
                <div
                    className="sd-drop"
                    data-over={over}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setOver(true);
                    }}
                    onDragLeave={() => setOver(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setOver(false);
                        drop([...e.dataTransfer.files]);
                    }}
                >
                    <Icon name="upload" size={22} />
                    <span>
                        <strong style={{ color: 'var(--ink)' }}>Drop extension.json and the archives here</strong>
                    </span>
                    <span className="xs">or choose each file below. The version comes from extension.json.</span>
                </div>
                {unknown.length > 0 && (
                    <Note tone="you">
                        <span className="small">Not sure where these go: {unknown.join(', ')}. Choose them with the buttons below.</span>
                    </Note>
                )}

                <ul className="stack" style={{ listStyle: 'none', margin: 0, padding: 0, gap: 8 }}>
                    {SLOTS.map((slot) => {
                        const file = files[slot.key];
                        const message = fieldError(slot.key);
                        const inputId = `upload-${slot.key}`;
                        return (
                            <li key={slot.key} className="sd-slot" data-slot={slot.key} data-filled={Boolean(file)}>
                                <div className="row" style={{ gap: 12, minWidth: 0 }}>
                                    <span className="fi" aria-hidden="true" style={{ width: 36, height: 36, borderRadius: 10, display: 'grid', placeItems: 'center', background: file ? 'var(--ok-bg)' : 'var(--accent-soft)', color: file ? 'var(--ok)' : 'var(--accent)', flexShrink: 0 }}>
                                        <Icon name={file ? 'check' : 'file'} size={16} />
                                    </span>
                                    <span className="col" style={{ gap: 0, minWidth: 0 }}>
                                        <label htmlFor={inputId} className="small" style={{ fontWeight: 600, color: 'var(--ink)' }}>
                                            {slot.label}
                                            {slot.optional && <span className="muted" style={{ fontWeight: 400 }}> (optional)</span>}
                                        </label>
                                        <span className="xs muted mono" style={{ overflowWrap: 'anywhere' }}>
                                            {file ? `${file.name} · ${bytes(file.size)}` : slot.hint}
                                        </span>
                                        {message && (
                                            <span className="err">
                                                <Icon name="alert" size={14} strokeWidth={2.4} />
                                                {message}
                                            </span>
                                        )}
                                    </span>
                                </div>
                                <div className="row" style={{ gap: 6 }}>
                                    {file && (
                                        <button type="button" className="btn btn-sm btn-quiet" onClick={() => setFiles({ ...files, [slot.key]: undefined })} aria-label={`Remove ${slot.label}`}>
                                            Remove
                                        </button>
                                    )}
                                    <button type="button" className="btn btn-sm" onClick={() => inputs.current[slot.key]?.click()} aria-label={`${file ? 'Replace' : 'Choose'} ${slot.label.toLowerCase()} file`}>
                                        {file ? 'Replace' : 'Choose'}
                                    </button>
                                    <input
                                        ref={(node) => {
                                            inputs.current[slot.key] = node;
                                        }}
                                        id={inputId}
                                        type="file"
                                        accept={slot.accept}
                                        className="sr-only"
                                        tabIndex={-1}
                                        onChange={(e) => {
                                            const picked = e.target.files?.[0];
                                            e.target.value = '';
                                            if (picked) setFiles((current) => ({ ...current, [slot.key]: picked }));
                                        }}
                                    />
                                </div>
                            </li>
                        );
                    })}
                </ul>
                <p className="xs muted">A manifest and at least one of the engine and API archives are needed.</p>

                <div className="grid2">
                    <Field label="Channel" error={fieldError('channel')} help="Leave it to extension.json, or pick one.">
                        {(id, aria) => (
                            <select id={id} {...aria} className="select" value={channel} onChange={(e) => setChannel(e.target.value)}>
                                <option value="">As in extension.json (stable by default)</option>
                                <option value="stable">Stable</option>
                                <option value="beta">Beta</option>
                            </select>
                        )}
                    </Field>
                    <div className="field" style={{ justifyContent: 'flex-end' }}>
                        <label className="check" style={{ minHeight: 44, alignItems: 'center' }}>
                            <input type="checkbox" checked={submit} onChange={(e) => setSubmit(e.target.checked)} />
                            <span>Submit for review when the checks pass</span>
                        </label>
                    </div>
                </div>
                <Field label="Release notes" optional error={fieldError('notes')} help="What changed. Shown on the extension page.">
                    {(id, aria) => <textarea id={id} {...aria} className="textarea" style={{ minHeight: 96 }} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={20000} />}
                </Field>

                {general && (
                    <Note tone="fail" role="alert">
                        {general}
                    </Note>
                )}
                {busy && (
                    <div className="meter" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress} aria-label="Uploading">
                        <span style={{ width: `${progress}%` }} />
                    </div>
                )}
                <div className="row between wrapflex">
                    <span className="small muted">Uploading the same version again replaces a release that failed its checks or was rejected.</span>
                    <button type="submit" className="btn btn-primary" disabled={!ready} aria-busy={busy || undefined}>
                        <Icon name={busy ? 'spinner' : 'upload'} size={16} />
                        {busy ? `Uploading… ${progress}%` : 'Upload and run checks'}
                    </button>
                </div>
            </div>
        </form>
    );
}
