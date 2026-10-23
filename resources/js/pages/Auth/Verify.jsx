import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AuthShell from '../../components/account/AuthShell';
import CodeInput from '../../components/account/CodeInput';
import Icon from '../../components/Icon';
import { useToast } from '../../components/Toasts';
import { Field, Note } from '../../components/Ui';
import { api } from '../../lib/api';

const RESEND_SECONDS = 60;

/**
 * Enter the 6-digit code emailed at registration (POST /v1/auth/verify), or send a new one
 * (POST /v1/auth/verify/resend, at most once a minute). Verified → sign in.
 */
export default function Verify({ email, sent }) {
    // The form element belongs to the shell; Enter submits through the form's handler.
    const submit = useRef(null);
    return (
        <AuthShell
            title="Verify your email"
            labelledBy="h-verify"
            onSubmit={(event) => {
                event.preventDefault();
                submit.current?.();
            }}
        >
            <VerifyForm initialEmail={email ?? ''} sent={Boolean(sent)} submitRef={submit} />
        </AuthShell>
    );
}

function VerifyForm({ initialEmail, sent, submitRef }) {
    const toast = useToast();
    const [email, setEmail] = useState(initialEmail);
    const [code, setCode] = useState('');
    const [error, setError] = useState(null);
    const [emailError, setEmailError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [wait, setWait] = useState(sent ? RESEND_SECONDS : 0);
    const submitting = useRef(false);

    useEffect(() => {
        if (wait <= 0) {
            return undefined;
        }
        const timer = setTimeout(() => setWait((seconds) => seconds - 1), 1000);
        return () => clearTimeout(timer);
    }, [wait]);

    async function verify(value = code) {
        if (submitting.current) {
            return;
        }
        if (!email.trim()) {
            setEmailError('Enter the email you registered with.');
            return;
        }
        if (value.length !== 6) {
            setError('Enter all six digits.');
            return;
        }
        submitting.current = true;
        setBusy(true);
        setError(null);
        setEmailError(null);
        try {
            await api.post('auth/verify', { email: email.trim(), code: value });
            router.visit(`/sign-in?email=${encodeURIComponent(email.trim())}&verified=1`);
        } catch (failure) {
            submitting.current = false;
            setBusy(false);
            if (failure.status === 422 && failure.field('email')) {
                setEmailError(failure.field('email'));
            } else if (failure.status === 422) {
                setError('That code is wrong or expired. Try again or send a new one.');
            } else if (failure.status === 404) {
                setEmailError('No account uses that email. Check it, or create an account.');
            } else {
                setError(failure.message);
            }
        }
    }

    useEffect(() => {
        submitRef.current = () => verify();
    });

    async function resend() {
        if (wait > 0) {
            return;
        }
        if (!email.trim()) {
            setEmailError('Enter the email you registered with.');
            return;
        }
        setWait(RESEND_SECONDS);
        try {
            await api.post('auth/verify/resend', { email: email.trim() });
            toast('If that account needs verifying, a new code is on its way.');
        } catch (failure) {
            setWait(0);
            toast(failure.message, { type: 'error' });
        }
    }

    const countdown = `${Math.floor(wait / 60)}:${String(wait % 60).padStart(2, '0')}`;

    return (
        <>
            <span className="empty-glyph" aria-hidden="true">
                <Icon name="mail" size={26} />
            </span>
            <div className="col" style={{ gap: 6 }}>
                <h1 id="h-verify" className="h2">
                    Check your email
                </h1>
                {initialEmail ? (
                    <p className="ink2">
                        We sent a 6-digit code to <strong style={{ color: 'var(--ink)' }}>{initialEmail}</strong>. It expires in 15 minutes.
                    </p>
                ) : (
                    <p className="ink2">Enter the email you registered with and the 6-digit code we sent to it.</p>
                )}
            </div>

            {!initialEmail && (
                <Field label="Email" error={emailError}>
                    {(id, aria) => <input id={id} {...aria} className={`input ${emailError ? 'is-error' : ''}`} type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} />}
                </Field>
            )}
            {initialEmail && emailError && (
                <Note tone="fail" role="alert">
                    {emailError} <Link href="/register">Create an account</Link>
                </Note>
            )}

            <fieldset className="col" style={{ border: 0, padding: 0, margin: 0, gap: 8 }}>
                <legend className="label" style={{ marginBottom: 8 }}>
                    Verification code
                </legend>
                <CodeInput
                    onChange={(value) => {
                        setCode(value);
                        setError(null);
                    }}
                    onComplete={(value) => verify(value)}
                    invalid={Boolean(error)}
                    disabled={busy}
                    describedBy={error ? 'verify-error' : 'verify-help'}
                    autoFocus={Boolean(initialEmail)}
                />
                {error ? (
                    <span id="verify-error" className="err" role="alert">
                        <Icon name="alert" size={14} strokeWidth={2.4} />
                        {error}
                    </span>
                ) : (
                    <span id="verify-help" className="help">
                        Pasting a 6-digit code fills every box and submits.
                    </span>
                )}
            </fieldset>

            <button type="submit" className="btn btn-primary btn-lg" disabled={busy} data-verify-submit>
                {busy && <Icon name="spinner" spin />}
                Verify email
            </button>
            <div className="row between wrapflex small" style={{ gap: 8 }}>
                <span className="muted">Didn’t get it? Check spam.</span>
                <button type="button" className="btn btn-quiet btn-sm" style={{ minHeight: 44 }} aria-disabled={wait > 0} onClick={resend} data-resend>
                    {wait > 0 ? `Resend in ${countdown}` : 'Resend code'}
                </button>
            </div>
        </>
    );
}
