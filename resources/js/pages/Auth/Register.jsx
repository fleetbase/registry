import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AuthShell from '../../components/account/AuthShell';
import Icon from '../../components/Icon';
import { Field, Note } from '../../components/Ui';
import { api } from '../../lib/api';

/**
 * How far a password meets the registry's rule (8+ characters with letters and numbers) and
 * beyond it: 0 empty, 1 too short or missing letters/numbers, 2 acceptable, 3 good, 4 strong.
 */
export function passwordStrength(password) {
    const value = String(password ?? '');
    if (!value) {
        return { score: 0, label: '' };
    }
    const letters = /[a-z]/i.test(value);
    const numbers = /\d/.test(value);
    if (value.length < 8 || !letters || !numbers) {
        return { score: 1, label: 'Too weak: needs 8 characters with letters and numbers.' };
    }
    let score = 2;
    if (value.length >= 12) {
        score++;
    }
    if (/[^a-z0-9]/i.test(value) || (/[a-z]/.test(value) && /[A-Z]/.test(value))) {
        score++;
    }
    return { score: Math.min(score, 4), label: ['', '', 'Acceptable.', 'Good.', 'Strong.'][Math.min(score, 4)] };
}

/** Create an account (POST /v1/auth/register), then enter the emailed code. */
export default function Register() {
    const [form, setForm] = useState({ name: '', email: '', username: '', password: '' });
    const [errors, setErrors] = useState({});
    const [failure, setFailure] = useState(null);
    const [busy, setBusy] = useState(false);
    const strength = passwordStrength(form.password);

    function set(key) {
        return (event) => setForm({ ...form, [key]: event.target.value });
    }

    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setErrors({});
        setFailure(null);
        const email = form.email.trim();
        try {
            await api.post('auth/register', {
                name: form.name.trim(),
                email,
                username: form.username.trim() || undefined,
                password: form.password,
            });
            router.visit(`/verify?email=${encodeURIComponent(email)}&sent=1`);
        } catch (error) {
            setBusy(false);
            if (error.status === 422 && Object.keys(error.fields).length) {
                setErrors({
                    name: error.field('name'),
                    email: error.field('email'),
                    username: error.field('username'),
                    password: error.field('password'),
                });
            } else {
                setFailure(error.message);
            }
        }
    }

    const emailTaken = errors.email && /taken|already/i.test(errors.email);

    return (
        <AuthShell title="Create your account" labelledBy="h-create" onSubmit={submit}>
            <div className="col" style={{ gap: 6 }}>
                <h1 id="h-create" className="h2">
                    Create your account
                </h1>
                <p className="ink2">Free. You get a personal organization to publish under.</p>
            </div>

            {failure && (
                <Note tone="fail" role="alert">
                    {failure}
                </Note>
            )}

            <Field label="Name" error={errors.name}>
                {(id, aria) => <input id={id} {...aria} className={`input ${errors.name ? 'is-error' : ''}`} autoComplete="name" value={form.name} onChange={set('name')} required />}
            </Field>
            <Field
                label="Work email"
                error={
                    errors.email &&
                    (emailTaken ? (
                        <>
                            An account already uses this email. <Link href={`/sign-in?email=${encodeURIComponent(form.email.trim())}`}>Sign in instead</Link>
                        </>
                    ) : (
                        errors.email
                    ))
                }
            >
                {(id, aria) => <input id={id} {...aria} className={`input ${errors.email ? 'is-error' : ''}`} type="email" autoComplete="email" value={form.email} onChange={set('email')} required />}
            </Field>
            <Field
                label="Username"
                optional
                error={errors.username}
                help={
                    <>
                        Becomes your personal package scope, like <code>@{form.username.trim() || 'pat'}</code>.
                    </>
                }
            >
                {(id, aria) => <input id={id} {...aria} className={`input mono ${errors.username ? 'is-error' : ''}`} autoComplete="username" autoCapitalize="none" spellCheck={false} value={form.username} onChange={set('username')} />}
            </Field>
            <Field label="Password" error={errors.password} help={strength.label ? `At least 8 characters with letters and numbers. ${strength.score > 1 ? strength.label : ''}`.trim() : 'At least 8 characters with letters and numbers.'}>
                {(id, aria) => (
                    <>
                        <input id={id} {...aria} className={`input ${errors.password ? 'is-error' : ''}`} type="password" autoComplete="new-password" value={form.password} onChange={set('password')} required />
                        {form.password && (
                            <div className="meter" aria-hidden="true">
                                <span style={{ width: `${strength.score * 25}%`, background: strength.score < 2 ? 'var(--fail)' : undefined }} />
                            </div>
                        )}
                    </>
                )}
            </Field>
            <button type="submit" className="btn btn-primary btn-lg" disabled={busy}>
                {busy && <Icon name="spinner" spin />}
                Create account
            </button>
            <p className="small muted" style={{ textAlign: 'center' }}>
                Already have an account? <Link href="/sign-in">Sign in</Link>
            </p>
            <p className="xs muted">
                By creating an account you agree to the{' '}
                <a href="https://fleetbase.io/terms" target="_blank" rel="noopener noreferrer">
                    publisher terms
                </a>
                .
            </p>
        </AuthShell>
    );
}
