import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AuthShell from '../../components/account/AuthShell';
import CodeInput from '../../components/account/CodeInput';
import { PasswordMeter, passwordHelp, passwordStrength } from '../../components/account/password';
import Icon from '../../components/Icon';
import { useToast } from '../../components/Toasts';
import { Field, Note } from '../../components/Ui';
import { api } from '../../lib/api';

const RESEND_SECONDS = 60;

/**
 * Forgot password: the account's email (POST /v1/auth/password/forgot), then the 6-digit code
 * from the email with a new password (POST /v1/auth/password/reset), then sign in. The reset
 * email links here with `email` and `sent`, which open the second step.
 */
export default function ForgotPassword({ email, sent }) {
    // The form element belongs to the shell; Enter submits through the current step's handler.
    const submit = useRef(null);
    return (
        <AuthShell
            title="Reset your password"
            labelledBy="h-forgot"
            onSubmit={(event) => {
                event.preventDefault();
                submit.current?.();
            }}
        >
            <ForgotPasswordForm initialEmail={email ?? ''} sent={Boolean(sent && email)} submitRef={submit} />
        </AuthShell>
    );
}

function ForgotPasswordForm({ initialEmail, sent, submitRef }) {
    const toast = useToast();
    const [step, setStep] = useState(sent ? 'code' : 'email');
    const [email, setEmail] = useState(initialEmail);
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState(null);
    const [busy, setBusy] = useState(false);
    const [wait, setWait] = useState(sent ? RESEND_SECONDS : 0);
    const passwordRef = useRef(null);
    const strength = passwordStrength(password);

    useEffect(() => {
        if (wait <= 0) {
            return undefined;
        }
        const timer = setTimeout(() => setWait((seconds) => seconds - 1), 1000);
        return () => clearTimeout(timer);
    }, [wait]);

    async function sendCode() {
        const address = email.trim();
        if (!address) {
            setErrors({ email: 'Enter the email you sign in with.' });
            return;
        }
        setBusy(true);
        setErrors({});
        setFailure(null);
        try {
            await api.post('auth/password/forgot', { email: address });
            setEmail(address);
            setStep('code');
            setWait(RESEND_SECONDS);
        } catch (error) {
            if (error.status === 422 && error.field('email')) {
                setErrors({ email: error.field('email') });
            } else {
                setFailure(error.message);
            }
        } finally {
            setBusy(false);
        }
    }

    async function reset() {
        const next = {};
        if (code.length !== 6) {
            next.code = 'Enter all six digits.';
        }
        if (strength.score < 2) {
            next.password = 'Use at least 8 characters with letters and numbers.';
        } else if (password !== confirmation) {
            next.password_confirmation = 'The new passwords don’t match.';
        }
        setErrors(next);
        setFailure(null);
        if (Object.keys(next).length) {
            return;
        }
        setBusy(true);
        try {
            await api.post('auth/password/reset', { email, code, password, password_confirmation: confirmation });
            router.visit(`/sign-in?email=${encodeURIComponent(email)}&reset=1`);
        } catch (error) {
            setBusy(false);
            if (error.status === 422 && Object.keys(error.fields).length) {
                setErrors({
                    code: error.field('code') && 'That code is wrong or expired. Try again or send a new one.',
                    password: error.field('password'),
                    email: error.field('email'),
                });
            } else {
                setFailure(error.message);
            }
        }
    }

    useEffect(() => {
        submitRef.current = () => (busy ? undefined : step === 'email' ? sendCode() : reset());
    });

    async function resend() {
        if (wait > 0) {
            return;
        }
        setWait(RESEND_SECONDS);
        try {
            await api.post('auth/password/forgot', { email });
            toast('If an account uses that email, a new code is on its way.');
        } catch (error) {
            setWait(0);
            toast(error.message, { type: 'error' });
        }
    }

    function changeEmail() {
        setStep('email');
        setCode('');
        setErrors({});
        setFailure(null);
    }

    const countdown = `${Math.floor(wait / 60)}:${String(wait % 60).padStart(2, '0')}`;

    if (step === 'email') {
        return (
            <>
                <div className="col" style={{ gap: 6 }}>
                    <h1 id="h-forgot" className="h2">
                        Reset your password
                    </h1>
                    <p className="ink2">Enter the email you sign in with. We’ll send it a 6-digit code to set a new password.</p>
                </div>
                {failure && (
                    <Note tone="fail" role="alert">
                        {failure}
                    </Note>
                )}
                <Field label="Email" error={errors.email}>
                    {(id, aria) => (
                        <input id={id} {...aria} className={`input ${errors.email ? 'is-error' : ''}`} type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} required autoFocus />
                    )}
                </Field>
                <button type="submit" className="btn btn-primary btn-lg" disabled={busy} data-send-code>
                    {busy && <Icon name="spinner" spin />}
                    Send code
                </button>
                <p className="small muted" style={{ textAlign: 'center' }}>
                    Remembered it? <Link href={`/sign-in${email.trim() ? `?email=${encodeURIComponent(email.trim())}` : ''}`}>Sign in</Link>
                </p>
            </>
        );
    }

    return (
        <>
            <span className="empty-glyph" aria-hidden="true">
                <Icon name="mail" size={26} />
            </span>
            <div className="col" style={{ gap: 6 }}>
                <h1 id="h-forgot" className="h2">
                    Check your email
                </h1>
                <p className="ink2">
                    If an account uses <strong style={{ color: 'var(--ink)' }}>{email}</strong>, we sent it a 6-digit code. It expires in 30 minutes.
                </p>
            </div>

            {failure && (
                <Note tone="fail" role="alert">
                    {failure}
                </Note>
            )}
            {errors.email && (
                <Note tone="fail" role="alert">
                    {errors.email}
                </Note>
            )}

            <fieldset className="col" style={{ border: 0, padding: 0, margin: 0, gap: 8 }}>
                <legend className="label" style={{ marginBottom: 8 }}>
                    Reset code
                </legend>
                <CodeInput
                    onChange={(value) => {
                        setCode(value);
                        setErrors((current) => ({ ...current, code: null }));
                    }}
                    // After CodeInput settles its own focus on the last box (a paste moves it there).
                    onComplete={() => setTimeout(() => passwordRef.current?.focus(), 0)}
                    invalid={Boolean(errors.code)}
                    disabled={busy}
                    describedBy={errors.code ? 'reset-code-error' : 'reset-code-help'}
                    autoFocus
                />
                {errors.code ? (
                    <span id="reset-code-error" className="err" role="alert">
                        <Icon name="alert" size={14} strokeWidth={2.4} />
                        {errors.code}
                    </span>
                ) : (
                    <span id="reset-code-help" className="help">
                        Pasting the code fills every box.
                    </span>
                )}
            </fieldset>

            <Field label="New password" error={errors.password} help={passwordHelp(strength)}>
                {(id, aria) => (
                    <>
                        <input
                            id={id}
                            {...aria}
                            ref={passwordRef}
                            className={`input ${errors.password ? 'is-error' : ''}`}
                            type="password"
                            autoComplete="new-password"
                            value={password}
                            onChange={(event) => {
                                setPassword(event.target.value);
                                setErrors((current) => ({ ...current, password: null }));
                            }}
                            required
                        />
                        <PasswordMeter strength={strength} />
                    </>
                )}
            </Field>
            <Field label="Repeat new password" error={errors.password_confirmation}>
                {(id, aria) => (
                    <input
                        id={id}
                        {...aria}
                        className={`input ${errors.password_confirmation ? 'is-error' : ''}`}
                        type="password"
                        autoComplete="new-password"
                        value={confirmation}
                        onChange={(event) => {
                            setConfirmation(event.target.value);
                            setErrors((current) => ({ ...current, password_confirmation: null }));
                        }}
                        required
                    />
                )}
            </Field>

            <button type="submit" className="btn btn-primary btn-lg" disabled={busy} data-reset-password>
                {busy && <Icon name="spinner" spin />}
                Set new password
            </button>
            <p className="xs muted">This signs you out everywhere and revokes your access tokens.</p>
            <div className="row between wrapflex small" style={{ gap: 8 }}>
                <button type="button" className="btn btn-quiet btn-sm" style={{ minHeight: 44 }} onClick={changeEmail} data-change-email>
                    Use a different email
                </button>
                <button type="button" className="btn btn-quiet btn-sm" style={{ minHeight: 44 }} aria-disabled={wait > 0} onClick={resend} data-resend>
                    {wait > 0 ? `Resend in ${countdown}` : 'Resend code'}
                </button>
            </div>
        </>
    );
}
