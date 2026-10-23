import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AuthShell from '../../components/account/AuthShell';
import Icon from '../../components/Icon';
import { useToast } from '../../components/Toasts';
import { Field, Note } from '../../components/Ui';
import { api } from '../../lib/api';

/**
 * Sign in with email and password (a cookie session from POST /v1/auth/session), then back to
 * the page that asked for it, else the Studio. A just-verified email arrives with `verified`.
 */
export default function SignIn({ email: initialEmail, intended, verified }) {
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

    return (
        <AuthShell title="Sign in" labelledBy="h-signin" onSubmit={submit}>
            {verified && <VerifiedToast />}
            <div className="col" style={{ gap: 6 }}>
                <h1 id="h-signin" className="h2">
                    Welcome back
                </h1>
                <p className="ink2">Sign in to publish and manage extensions.</p>
            </div>

            {error?.kind === 'mismatch' && (
                <Note tone="fail" role="alert">
                    That email and password don’t match. Check both and try again.
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
            <Field label="Password" error={fields.password}>
                {(id, aria) => (
                    <input
                        id={id}
                        {...aria}
                        className={`input ${error?.kind === 'mismatch' || fields.password ? 'is-error' : ''}`}
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        required
                        autoFocus={Boolean(initialEmail)}
                    />
                )}
            </Field>
            <button type="submit" className="btn btn-primary btn-lg" disabled={busy} data-sign-in-submit>
                {busy && <Icon name="spinner" spin />}
                Sign in
            </button>
            <p className="small muted" style={{ textAlign: 'center' }}>
                New here? <Link href="/register">Create an account</Link>
            </p>
        </AuthShell>
    );
}

/** The confirmation for a just-verified email, once. */
function VerifiedToast() {
    const toast = useToast();
    const shown = useRef(false);
    useEffect(() => {
        if (!shown.current) {
            shown.current = true;
            toast('Email verified. Sign in to continue.');
        }
    }, [toast]);
    return null;
}
