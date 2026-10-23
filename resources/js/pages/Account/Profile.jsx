import { useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import AccountHeader from '../../components/account/AccountHeader';
import { GithubMark, useGithubSignIn } from '../../components/account/GithubButton';
import Dialog from '../../components/Dialog';
import Icon from '../../components/Icon';
import { useToast } from '../../components/Toasts';
import { Field, Note } from '../../components/Ui';
import { api } from '../../lib/api';

/** Time zones the browser knows, with the account's own kept in the list. */
function timezones(current) {
    let zones;
    try {
        zones = Intl.supportedValuesOf('timeZone');
    } catch {
        zones = [];
    }
    return [...new Set(['UTC', ...(current ? [current] : []), ...zones])].sort((a, b) => (a === 'UTC' ? -1 : b === 'UTC' ? 1 : a.localeCompare(b)));
}

/** Your name, username and time zone (PATCH /v1/account), your password, and GitHub. */
export default function Profile() {
    return (
        <SiteLayout title="Profile and password">
            <div className="wrap" style={{ paddingTop: 40, paddingBottom: 80 }}>
                <div className="stack-lg">
                    <AccountHeader current="profile" />
                    <section className="card" aria-labelledby="h-profile">
                        <div className="card-head">
                            <h2 id="h-profile" className="h3">
                                Your profile and password
                            </h2>
                        </div>
                        <div className="card-body grid2" style={{ alignItems: 'start' }}>
                            <ProfileForm />
                            <PasswordForm />
                        </div>
                    </section>
                    <GithubCard />
                </div>
            </div>
        </SiteLayout>
    );
}

function ProfileForm() {
    const account = usePage().props.auth.account;
    const toast = useToast();
    const [form, setForm] = useState({ name: account.name ?? '', username: account.username ?? '', timezone: account.timezone ?? '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const zones = useMemo(() => timezones(account.timezone), [account.timezone]);

    async function save(event) {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        try {
            await api.patch('account', {
                name: form.name.trim(),
                // A username, once set, cannot be blanked; leaving it empty keeps none.
                ...(form.username.trim() ? { username: form.username.trim() } : {}),
                timezone: form.timezone || null,
            });
            toast('Profile saved.');
            router.reload();
        } catch (error) {
            if (error.status === 422) {
                setErrors({ name: error.field('name'), username: error.field('username'), timezone: error.field('timezone') });
            } else {
                toast(error.message, { type: 'error' });
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <form method="post" className="stack" onSubmit={save} aria-label="Profile" noValidate>
            <Field label="Name" error={errors.name}>
                {(id, aria) => <input id={id} {...aria} className={`input ${errors.name ? 'is-error' : ''}`} autoComplete="name" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />}
            </Field>
            <Field label="Username" error={errors.username} help="Lowercase letters, numbers and dashes.">
                {(id, aria) => (
                    <input
                        id={id}
                        {...aria}
                        className={`input mono ${errors.username ? 'is-error' : ''}`}
                        autoComplete="username"
                        autoCapitalize="none"
                        spellCheck={false}
                        value={form.username}
                        onChange={(event) => setForm({ ...form, username: event.target.value })}
                    />
                )}
            </Field>
            <Field label="Time zone" error={errors.timezone} help="Dates and times on the site use it.">
                {(id, aria) => (
                    <select id={id} {...aria} className="select" value={form.timezone} onChange={(event) => setForm({ ...form, timezone: event.target.value })}>
                        <option value="">Not set</option>
                        {zones.map((zone) => (
                            <option key={zone} value={zone}>
                                {zone}
                            </option>
                        ))}
                    </select>
                )}
            </Field>
            <button type="submit" className="btn" style={{ alignSelf: 'flex-start' }} disabled={busy} data-save-profile>
                {busy && <Icon name="spinner" spin />}
                Save profile
            </button>
        </form>
    );
}

/** Where to set a first password (or a forgotten one): the reset code goes to the account's email. */
function forgotHref(account) {
    return `/forgot-password?email=${encodeURIComponent(account.email)}`;
}

function PasswordForm() {
    const account = usePage().props.auth.account;
    if (account.has_password === false) {
        return (
            <div className="stack" aria-label="Password" role="group">
                <Note tone="info">You sign in with GitHub, so this account has no password yet. To set one, we’ll email you a code.</Note>
                <Link href={forgotHref(account)} className="btn" style={{ alignSelf: 'flex-start' }} data-set-password>
                    Set a password
                </Link>
            </div>
        );
    }
    return <ChangePasswordForm />;
}

function ChangePasswordForm() {
    const toast = useToast();
    const empty = { current_password: '', password: '', password_confirmation: '' };
    const [form, setForm] = useState(empty);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    async function change(event) {
        event.preventDefault();
        setErrors({});
        if (form.password !== form.password_confirmation) {
            setErrors({ password_confirmation: 'The new passwords don’t match.' });
            return;
        }
        setBusy(true);
        try {
            await api.post('account/password', form);
            setForm(empty);
            toast('Password changed. Your other browsers are signed out.');
            router.reload();
        } catch (error) {
            if (error.status === 422) {
                setErrors({ current_password: error.field('current_password'), password: error.field('password'), password_confirmation: error.field('password_confirmation') });
            } else {
                toast(error.message, { type: 'error' });
            }
        } finally {
            setBusy(false);
        }
    }

    const input = (key, label, autoComplete) => (
        <Field label={label} error={errors[key]}>
            {(id, aria) => <input id={id} {...aria} className={`input ${errors[key] ? 'is-error' : ''}`} type="password" autoComplete={autoComplete} value={form[key]} onChange={(event) => setForm({ ...form, [key]: event.target.value })} />}
        </Field>
    );

    return (
        <form method="post" className="stack" onSubmit={change} aria-label="Change password" noValidate>
            {input('current_password', 'Current password', 'current-password')}
            {input('password', 'New password', 'new-password')}
            {input('password_confirmation', 'Repeat new password', 'new-password')}
            <div className="row wrapflex">
                <button type="submit" className="btn" disabled={busy || !form.current_password || !form.password} data-change-password>
                    {busy && <Icon name="spinner" spin />}
                    Change password
                </button>
                <span className="xs muted">Signs out your other browsers. Tokens keep working.</span>
            </div>
        </form>
    );
}

/**
 * The GitHub connection: connected as @login with Disconnect (DELETE /v1/account/github, which
 * needs a password to fall back on), or Connect (the OAuth round trip, which links GitHub to
 * this account). Hidden when the registry has no GitHub app and nothing is connected.
 */
function GithubCard() {
    const account = usePage().props.auth.account;
    const configured = useGithubSignIn();
    const toast = useToast();
    const [confirming, setConfirming] = useState(false);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const login = account.github_login;

    if (!login && !configured) {
        return null;
    }

    async function disconnect() {
        setBusy(true);
        setError(null);
        try {
            await api.delete('account/github');
            setConfirming(false);
            toast('GitHub disconnected.');
            router.reload();
        } catch (failure) {
            setConfirming(false);
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="card" aria-labelledby="h-github" data-github-card>
            <div className="card-head">
                <h2 id="h-github" className="h3">
                    GitHub
                </h2>
            </div>
            <div className="card-body stack">
                <div className="row between wrapflex" style={{ gap: 16 }}>
                    <div className="row" style={{ gap: 12, minWidth: 0 }}>
                        <span className="ext-icon sm" style={{ background: 'var(--ink)', color: 'var(--surface)' }} aria-hidden="true">
                            <GithubMark size={20} />
                        </span>
                        {login ? (
                            <div className="col" style={{ gap: 2, minWidth: 0 }}>
                                <span>
                                    Connected as{' '}
                                    <a href={`https://github.com/${encodeURIComponent(login)}`} target="_blank" rel="noopener noreferrer" className="mono">
                                        @{login}
                                    </a>
                                </span>
                                <span className="small muted">You can sign in with GitHub instead of your password.</span>
                            </div>
                        ) : (
                            <div className="col" style={{ gap: 2, minWidth: 0 }}>
                                <span>Not connected</span>
                                <span className="small muted">Connect GitHub to sign in with it instead of your password.</span>
                            </div>
                        )}
                    </div>
                    {login ? (
                        <button type="button" className="btn btn-danger" onClick={() => setConfirming(true)} data-disconnect-github>
                            Disconnect
                        </button>
                    ) : (
                        <a href="/auth/github" className="btn" data-connect-github>
                            <GithubMark />
                            Connect GitHub
                        </a>
                    )}
                </div>
                {error && (
                    <Note tone="fail" role="alert">
                        {error}
                        {account.has_password === false && (
                            <>
                                {' '}
                                <Link href={forgotHref(account)}>Set a password</Link>
                            </>
                        )}
                    </Note>
                )}
            </div>

            <Dialog
                open={confirming}
                title="Disconnect GitHub?"
                labelledBy="disconnect-github-title"
                onClose={() => setConfirming(false)}
                footer={
                    <>
                        <button type="button" className="btn" onClick={() => setConfirming(false)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger-solid" onClick={disconnect} disabled={busy} data-confirm-disconnect>
                            {busy && <Icon name="spinner" spin />}
                            Disconnect
                        </button>
                    </>
                }
            >
                <p className="ink2">
                    You won’t be able to sign in with <span className="mono">@{login}</span> any more. {account.has_password === false ? 'This account has no password yet, so set one first.' : 'Sign in with your email and password instead.'}
                </p>
            </Dialog>
        </section>
    );
}
