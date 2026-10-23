import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Icon from '../Icon';
import Dialog from '../Dialog';
import { StatusChip } from '../Status';
import { Field } from '../Ui';
import { useToast } from '../Toasts';
import { api } from '../../lib/api';
import { initials } from '../../lib/format';

export const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
export const ICON_MAX = 1024 * 1024;
export const SCREENSHOT_MAX = 5 * 1024 * 1024;
export const MAX_SCREENSHOTS = 8;

/** Why an image can't be uploaded, or null. */
export function imageProblem(file, max) {
    if (!IMAGE_TYPES.includes(file.type)) {
        return `${file.name}: use a PNG, JPG or WebP image.`;
    }
    if (file.size > max) {
        return `${file.name} is larger than ${max >= 1024 * 1024 ? `${max / 1024 / 1024} MB` : `${max / 1024} KB`}.`;
    }
    return null;
}

/**
 * Icon and screenshots. Uploads, reorders and deletes go straight to the API (they never need
 * review) and reload the `extension` prop. The Font Awesome icon name is a listing field and
 * saves with the rest of the draft.
 */
export default function MediaSection({ extension, faIcon, onFaIcon, faIconError }) {
    const toast = useToast();
    const slug = extension.slug;
    const screenshots = extension.screenshots ?? [];
    const iconInput = useRef(null);
    const shotInput = useRef(null);
    const [iconBusy, setIconBusy] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [uploads, setUploads] = useState([]);
    const [moving, setMoving] = useState(false);
    const [over, setOver] = useState(false);
    const [naming, setNaming] = useState(Boolean(faIcon));
    const remaining = MAX_SCREENSHOTS - screenshots.length - uploads.filter((u) => !u.error).length;

    const reload = () => new Promise((resolve) => router.reload({ only: ['extension'], onFinish: resolve }));

    async function uploadIcon(file) {
        const problem = imageProblem(file, ICON_MAX);
        if (problem) {
            toast(problem, { type: 'error' });
            return;
        }
        setIconBusy(true);
        try {
            await api.upload(`/publisher/extensions/${slug}/icon`, { icon: file });
            await reload();
            toast('Icon updated.');
        } catch (e) {
            toast(e.field?.('icon') ?? e.message, { type: 'error' });
        } finally {
            setIconBusy(false);
        }
    }

    async function addScreenshots(files) {
        const list = [...files];
        if (list.length > remaining) {
            toast(`A listing can have ${MAX_SCREENSHOTS} screenshots. ${remaining > 0 ? `Only the first ${remaining} will be added.` : 'Remove one first.'}`, { type: 'error' });
        }
        const accepted = [];
        for (const file of list.slice(0, Math.max(remaining, 0))) {
            const problem = imageProblem(file, SCREENSHOT_MAX);
            if (problem) {
                toast(problem, { type: 'error' });
            } else {
                accepted.push({ key: `${file.name}-${file.size}-${Math.random()}`, file, name: file.name, progress: 0, error: null });
            }
        }
        if (!accepted.length) {
            return;
        }
        setUploads((current) => [...current, ...accepted]);
        for (const item of accepted) {
            try {
                await api.upload(`/publisher/extensions/${slug}/screenshots`, { screenshot: item.file }, { onProgress: (progress) => setUploads((current) => current.map((u) => (u.key === item.key ? { ...u, progress } : u))) });
                await reload();
                setUploads((current) => current.filter((u) => u.key !== item.key));
            } catch (e) {
                setUploads((current) => current.map((u) => (u.key === item.key ? { ...u, error: e.field?.('screenshot') ?? e.message } : u)));
            }
        }
    }

    async function move(index, delta) {
        const order = screenshots.map((s) => s.id);
        const target = index + delta;
        if (target < 0 || target >= order.length) {
            return;
        }
        [order[index], order[target]] = [order[target], order[index]];
        setMoving(true);
        try {
            await api.patch(`/publisher/extensions/${slug}/screenshots`, { order });
            await reload();
        } catch (e) {
            toast(e.message, { type: 'error' });
        } finally {
            setMoving(false);
        }
    }

    async function confirmRemove() {
        const target = confirm;
        setConfirm({ ...target, busy: true });
        try {
            if (target.kind === 'icon') {
                await api.delete(`/publisher/extensions/${slug}/icon`);
            } else {
                await api.delete(`/publisher/extensions/${slug}/screenshots/${target.id}`);
            }
            await reload();
            toast(target.kind === 'icon' ? 'Icon removed.' : 'Screenshot removed.');
            setConfirm(null);
        } catch (e) {
            toast(e.message, { type: 'error' });
            setConfirm({ ...target, busy: false });
        }
    }

    return (
        <section id="s-media" className="sd-sec card" aria-labelledby="h-media">
            <div className="card-head">
                <h2 id="h-media" className="h3">
                    Icon and screenshots
                </h2>
                <span className="small muted">{screenshots.length} of 8 · first is the cover</span>
            </div>
            <div className="card-body stack">
                <div className="row wrapflex" style={{ gap: 20, alignItems: 'flex-start' }}>
                    {extension.icon_url ? (
                        <span className="ext-icon xl" style={{ background: 'var(--surface-2)', border: '1px solid var(--line)' }}>
                            <img src={extension.icon_url} alt={`${extension.name} icon`} style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: 'inherit' }} />
                        </span>
                    ) : faIcon ? (
                        <span className="ext-icon xl i-slate" aria-hidden="true">
                            {initials(extension.name)}
                        </span>
                    ) : (
                        <span className="ext-icon xl" style={{ background: 'var(--bg-tint)', color: 'var(--muted)', border: '2px dashed var(--line-strong)' }} aria-hidden="true">
                            <Icon name="image" size={28} />
                        </span>
                    )}
                    <div className="col grow" style={{ gap: 8, minWidth: 220 }}>
                        <span className="h4">{extension.icon_url ? 'Icon' : faIcon ? `Font Awesome icon: ${faIcon}` : 'No icon yet'}</span>
                        <span className="small muted">PNG, JPG or WebP, square, at least 512 px, up to 1 MB. Or name a Font Awesome icon.</span>
                        <div className="row wrapflex">
                            <button type="button" className="btn btn-sm" onClick={() => iconInput.current?.click()} disabled={iconBusy} aria-busy={iconBusy || undefined}>
                                <Icon name={iconBusy ? 'spinner' : 'upload'} size={16} />
                                {iconBusy ? 'Uploading…' : extension.icon_url ? 'Replace icon' : 'Upload icon'}
                            </button>
                            {extension.icon_url && (
                                <button type="button" className="btn btn-sm btn-quiet" onClick={() => setConfirm({ kind: 'icon' })}>
                                    Remove icon
                                </button>
                            )}
                            {!naming && (
                                <button type="button" className="btn btn-sm btn-quiet" onClick={() => setNaming(true)}>
                                    Use an icon name
                                </button>
                            )}
                        </div>
                        <input ref={iconInput} type="file" accept="image/png,image/jpeg,image/webp" hidden aria-label="Icon file" onChange={(e) => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (file) {
                                    uploadIcon(file);
                                }
                            }}
                        />
                        {naming && (
                            <Field label="Font Awesome icon name" optional help="For example truck-fast. Saves with the listing." error={faIconError}>
                                {(id, aria) => <input id={id} {...aria} className="input mono" value={faIcon ?? ''} onChange={(e) => onFaIcon(e.target.value.toLowerCase())} placeholder="truck-fast" maxLength={64} autoComplete="off" />}
                            </Field>
                        )}
                    </div>
                </div>

                <ul className="grid4" style={{ gap: 12, listStyle: 'none', margin: 0, padding: 0 }} aria-label="Screenshots">
                    {screenshots.map((shot, index) => (
                        <li key={shot.id} className="sd-shot-tile" data-screenshot={shot.id}>
                            <img src={shot.url} alt={shot.caption || `Screenshot ${index + 1}`} />
                            {index === 0 && (
                                <StatusChip tone="ok" className="cover">
                                    Cover
                                </StatusChip>
                            )}
                            <span className="tools">
                                <button type="button" className="sd-mini-btn" aria-label={`Move screenshot ${index + 1} earlier`} disabled={moving || index === 0} onClick={() => move(index, -1)}>
                                    <Icon name="arrowLeft" size={16} />
                                </button>
                                <button type="button" className="sd-mini-btn" aria-label={`Move screenshot ${index + 1} later`} disabled={moving || index === screenshots.length - 1} onClick={() => move(index, 1)}>
                                    <Icon name="arrowRight" size={16} />
                                </button>
                                <button type="button" className="sd-mini-btn" aria-label={`Remove screenshot ${index + 1}`} onClick={() => setConfirm({ kind: 'screenshot', id: shot.id, index })}>
                                    <Icon name="trash" size={16} />
                                </button>
                            </span>
                            {shot.caption && <span className="cap">{shot.caption}</span>}
                        </li>
                    ))}
                    {uploads.map((upload) => (
                        <li key={upload.key} className="sd-shot-tile" style={{ flexDirection: 'column', alignItems: 'stretch', justifyContent: 'flex-end', gap: 6 }} data-uploading>
                            <span className="small" style={{ overflowWrap: 'anywhere', color: upload.error ? 'var(--fail)' : undefined }}>
                                {upload.error ? upload.error : `Uploading ${upload.name}… ${upload.progress}%`}
                            </span>
                            {upload.error ? (
                                <button type="button" className="btn btn-sm" onClick={() => setUploads((current) => current.filter((u) => u.key !== upload.key))}>
                                    Dismiss
                                </button>
                            ) : (
                                <div className="meter" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={upload.progress} aria-label={`Uploading ${upload.name}`}>
                                    <span style={{ width: `${upload.progress}%` }} />
                                </div>
                            )}
                        </li>
                    ))}
                    {remaining > 0 && (
                        <li>
                            <button
                                type="button"
                                className="sd-add-tile"
                                style={{ width: '100%' }}
                                data-over={over}
                                onClick={() => shotInput.current?.click()}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setOver(true);
                                }}
                                onDragLeave={() => setOver(false)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setOver(false);
                                    addScreenshots(e.dataTransfer.files);
                                }}
                            >
                                <Icon name="plus" size={20} />
                                <strong style={{ color: 'var(--ink)' }}>Add screenshots</strong>
                                Drop or browse · 1280×800 · up to 5 MB
                            </button>
                        </li>
                    )}
                </ul>
                <input
                    ref={shotInput}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    multiple
                    hidden
                    aria-label="Screenshot files"
                    onChange={(e) => {
                        addScreenshots(e.target.files ?? []);
                        e.target.value = '';
                    }}
                />
                <span className="help">Use the arrow buttons to reorder. Screenshots change without review.</span>
            </div>

            <Dialog
                open={Boolean(confirm)}
                title={confirm?.kind === 'icon' ? 'Remove the icon?' : `Remove screenshot ${(confirm?.index ?? 0) + 1}?`}
                labelledBy="media-confirm-title"
                onClose={() => !confirm?.busy && setConfirm(null)}
                footer={
                    <>
                        <button type="button" className="btn" onClick={() => setConfirm(null)} disabled={confirm?.busy}>
                            Keep it
                        </button>
                        <button type="button" className="btn btn-danger-solid" onClick={confirmRemove} disabled={confirm?.busy} data-autofocus>
                            {confirm?.busy ? 'Removing…' : 'Remove'}
                        </button>
                    </>
                }
            >
                <p className="ink2">{confirm?.kind === 'icon' ? 'The listing shows its initials until you upload another icon. A listing needs an icon before review.' : 'The image is deleted from the listing at once. This does not need review.'}</p>
            </Dialog>
        </section>
    );
}
