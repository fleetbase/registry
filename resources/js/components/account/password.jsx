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

/** The help line under a new-password field: the rule, then how strong the password is. */
export function passwordHelp(strength) {
    const rule = 'At least 8 characters with letters and numbers.';
    return strength.score > 1 ? `${rule} ${strength.label}` : rule;
}

/** The strength bar under a new-password field (decorative: the help text says the same). */
export function PasswordMeter({ strength }) {
    if (!strength.score) {
        return null;
    }
    return (
        <div className="meter" aria-hidden="true">
            <span style={{ width: `${strength.score * 25}%`, background: strength.score < 2 ? 'var(--fail)' : undefined }} />
        </div>
    );
}
