import { useState } from 'react';
import Icon from '../Icon';
import { Field, Note, Segmented } from '../Ui';
import { api } from '../../lib/api';
import { shortDate } from '../../lib/format';
import { EXPIRY_DAYS, expiryDate, tokenScopes } from './tokens';
import './account.css';

/** The organization a new token acts for by default: the first shared one, else the first. */
function defaultOrganization(organizations) {
    return (organizations.find((organization) => !organization.is_personal) ?? organizations[0])?.id ?? '';
}

/**
 * New token: a name, the organization it acts for (or all), what it can do and when it expires.
 * POST /v1/account/tokens; `onCreated` gets the response with the plaintext token.
 */
export default function NewTokenForm({ organizations = [], isStaff = false, initial, onCreated, onCancel }) {
    const scopes = tokenScopes(isStaff);
    const [name, setName] = useState(initial?.name ?? '');
    const [organization, setOrganization] = useState(initial ? (initial.organization ?? '') : defaultOrganization(organizations));
    const [selected, setSelected] = useState(initial?.scopes?.filter((scope) => scope in scopes) ?? ['publish']);
    const [days, setDays] = useState(180);
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState(null);
    const [busy, setBusy] = useState(false);

    function toggle(scope) {
        setSelected(selected.includes(scope) ? selected.filter((item) => item !== scope) : [...selected, scope]);
        setErrors({ ...errors, scopes: null });
    }

    async function submit(event) {
        event.preventDefault();
        setFailure(null);
        const local = {
            name: name.trim() ? null : 'Name it after where it will be used.',
            scopes: selected.length ? null : 'Pick at least one thing it can do.',
        };
        if (local.name || local.scopes) {
            setErrors(local);
            return;
        }
        setErrors({});
        setBusy(true);
        try {
            const created = await api.post('account/tokens', {
                name: name.trim(),
                scopes: selected,
                expires_in_days: days,
                organization: organization || undefined,
            });
            onCreated(created);
        } catch (error) {
            setBusy(false);
            if (error.status === 422 && Object.keys(error.fields).length) {
                setErrors({
                    name: error.field('name'),
                    scopes: error.field('scopes') ?? Object.entries(error.fields).find(([key]) => key.startsWith('scopes.'))?.[1]?.[0],
                    organization: error.field('organization'),
                    expires_in_days: error.field('expires_in_days'),
                });
            } else {
                setFailure(error.message);
            }
        }
    }

    return (
        <form className="card" aria-labelledby="h-new-token" style={{ boxShadow: 'var(--e2)' }} onSubmit={submit} noValidate data-new-token-form>
            <div className="card-head">
                <h2 id="h-new-token" className="h3">
                    New token
                </h2>
                <button type="button" className="icon-btn" aria-label="Close" onClick={onCancel}>
                    <Icon name="close" />
                </button>
            </div>
            <div className="card-body stack">
                {failure && (
                    <Note tone="fail" role="alert">
                        {failure}
                    </Note>
                )}
                <div className="grid2">
                    <Field label="Name" error={errors.name} help="Where it will be used, so you can revoke the right one later.">
                        {(id, aria) => <input id={id} {...aria} className={`input ${errors.name ? 'is-error' : ''}`} placeholder="GitHub Actions: publish" value={name} onChange={(event) => setName(event.target.value)} autoFocus />}
                    </Field>
                    <Field label="Acts for" error={errors.organization} help="Pinning limits the damage if it leaks.">
                        {(id, aria) => (
                            <select id={id} {...aria} className="select" value={organization} onChange={(event) => setOrganization(event.target.value)}>
                                {organizations.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name} only
                                    </option>
                                ))}
                                <option value="">All my organizations</option>
                            </select>
                        )}
                    </Field>
                </div>

                <fieldset className="stack" style={{ border: 0, padding: 0, margin: 0 }} aria-describedby={errors.scopes ? 'token-scopes-error' : undefined}>
                    <legend className="label" style={{ marginBottom: 4 }}>
                        What it can do
                    </legend>
                    <div className="grid2" style={{ gap: 10 }}>
                        {Object.entries(scopes).map(([scope, { label, description }]) => {
                            const on = selected.includes(scope);
                            return (
                                <button key={scope} type="button" className="scope" aria-pressed={on} onClick={() => toggle(scope)} data-scope={scope}>
                                    <span className="box" aria-hidden="true">
                                        <Icon name="check" size={14} strokeWidth={3} />
                                    </span>
                                    <span className="col" style={{ gap: 2 }}>
                                        <span className="h4">{label}</span>
                                        <span className="small muted">{description}</span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    {errors.scopes && (
                        <span id="token-scopes-error" className="err" role="alert">
                            <Icon name="alert" size={14} strokeWidth={2.4} />
                            {errors.scopes}
                        </span>
                    )}
                </fieldset>

                <div className="field">
                    <span className="label" id="token-expiry-label">
                        Expires
                    </span>
                    <div className="row wrapflex">
                        <Segmented role="radio" label="Expiry" value={days} onChange={setDays} options={EXPIRY_DAYS.map((value) => ({ value, label: `${value} days` }))} />
                        <span className="small muted" data-expiry-date>
                            Until {shortDate(expiryDate(days))}
                        </span>
                    </div>
                    <span className={errors.expires_in_days ? 'err' : 'help'}>{errors.expires_in_days ?? 'There is no “never”. Set a reminder to rotate it.'}</span>
                </div>

                <div className="row between wrapflex">
                    <span className="small muted">You’ll see the token once, on the next screen.</span>
                    <div className="row wrapflex">
                        <button type="button" className="btn" onClick={onCancel}>
                            Cancel
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={busy} data-create-token>
                            {busy && <Icon name="spinner" spin />}
                            Create token
                        </button>
                    </div>
                </div>
            </div>
        </form>
    );
}
