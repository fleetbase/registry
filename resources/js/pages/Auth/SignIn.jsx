import { useEffect, useId, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AuthShell from '../../components/account/AuthShell';
import GithubButton from '../../components/account/GithubButton';
import Icon from '../../components/Icon';
import { useToast } from '../../components/Toasts';
import { Field, Note } from '../../components/Ui';
import { api } from '../../lib/api';

/**
 * Sign in with email and password (a cookie session from POST /v1/auth/session), then back to
 * the page that asked for it, else the Studio. A just-verified email arrives with `verified`, a
 * just-reset password with `reset`. "Continue with GitHub" shows when the registry has it set up.
 */
export default function SignIn({ email: initialEmail, intended, verified, reset }) {
    const passwordId = useId();
    const [email, setEmail] = useState(initialEmail ?? '');
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [fields, setFields] = useState({});
    const [busy, setBusy] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError(null);
        setFields({});
        try {
            await api.post('auth/session', { email: email.trim(), password });
            router.visit(intended || '/studio', { replace: true });
        } catch (failure) {
            setBusy(false);
            if (failure.status === 401) {
                setError({ kind: 'mismatch' });
            } else if (failure.status === 403 && failure.code === 'email_unverified') {
                setError({ kind: 'unverified' });
            } else if (failure.status === 422 && Object.keys(failure.fields).length) {
                setFields({ email: failure.field('email'), password: failure.field('password') });
            } else {
                setError({ kind: 'other', message: failure.message });
            }
        }
    }

    const verifyHref = `/verify?email=${encodeURIComponent(email.trim())}`;
    const forgotHref = email.trim() ? `/forgot-password?email=${encodeURIComponent(email.trim())}` : '/forgot-password';
    const passwordError = fields.password;

    return (
        <AuthShell title="Sign in" labelledBy="h-signin" onSubmit={submit}>
            {verified && <ToastOnce message="Email verified. Sign in to continue." />}
            {reset && <ToastOnce message="Password changed. Sign in with the new one." />}
            <div className="col" style={{ gap: 6 }}>
                <h1 id="h-signin" className="h2">
                    Welcome back
                </h1>
                <p className="ink2">Sign in to publish and manage extensions.</p>
            </div>

            {error?.kind === 'mismatch' && (
                <Note tone="fail" role="alert">
                    That email and password don’t match. Check both, or <Link href={forgotHref}>reset your password</Link>.
                </Note>
            )}
            {error?.kind === 'unverified' && (
                <Note tone="you" role="alert">
                    Verify your email before signing in. <Link href={verifyHref}>Enter your code</Link>
                </Note>
            )}
            {error?.kind === 'other' && (
                <Note tone="fail" role="alert">
                    {error.message}
                </Note>
            )}

            <Field label="Email" error={fields.email}>
                {(id, aria) => (
                    <input id={id} {...aria} className={`input ${fields.email ? 'is-error' : ''}`} type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} required autoFocus={!initialEmail} />
                )}
            </Field>
            {/* Field's markup, with "Forgot?" beside the label (a link cannot sit inside one). */}
            <div className="field">
                <div className="row between">
                    <label className="label" htmlFor={passwordId}>
                        Password
                    </label>
                    <Link href={forgotHref} className="small" style={{ padding: '12px 0', margin: '-12px 0' }} data-forgot>
                        Forgot?
                    </Link>
                </div>
                <input
                    id={passwordId}
                    aria-describedby={passwordError ? `${passwordId}-help` : undefined}
                    aria-invalid={passwordError ? true : undefined}
                    className={`input ${error?.kind === 'mismatch' || passwordError ? 'is-error' : ''}`}
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    required
                    autoFocus={Boolean(initialEmail)}
                />
                {passwordError && (
                    <span id={`${passwordId}-help`} className="err">
                        <Icon name="alert" size={14} strokeWidth={2.4} />
                        {passwordError}
                    </span>
                )}
            </div>
            <button type="submit" className="btn btn-primary btn-lg" disabled={busy} data-sign-in-submit>
                {busy && <Icon name="spinner" spin />}
                Sign in
            </button>
            <GithubButton />
            <p className="small muted" style={{ textAlign: 'center' }}>
                New here? <Link href="/register">Create an account</Link>
            </p>
        </AuthShell>
    );
}

/** A confirmation toast, once (a just-verified email, a just-reset password). */
function ToastOnce({ message }) {
    const toast = useToast();
    const shown = useRef(false);
    useEffect(() => {
        if (!shown.current) {
            shown.current = true;
            toast(message);
        }
    }, [toast, message]);
    return null;
}
