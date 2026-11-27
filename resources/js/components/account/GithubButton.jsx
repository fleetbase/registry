import { usePage } from '@inertiajs/react';

/** GitHub's mark, drawn in the current text colour. */
export function GithubMark({ size = 18 }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M12 .5C5.65.5.5 5.65.5 12a11.5 11.5 0 0 0 7.86 10.92c.58.1.79-.25.79-.56v-2c-3.2.7-3.87-1.54-3.87-1.54-.52-1.33-1.28-1.69-1.28-1.69-1.04-.71.08-.7.08-.7 1.15.08 1.76 1.19 1.76 1.19 1.03 1.76 2.69 1.25 3.35.96.1-.75.4-1.25.73-1.54-2.55-.29-5.24-1.28-5.24-5.68 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.83 1.19 3.09 0 4.41-2.69 5.38-5.25 5.67.41.36.78 1.06.78 2.14v3.17c0 .31.21.67.8.56A11.5 11.5 0 0 0 23.5 12C23.5 5.65 18.35.5 12 .5z" />
        </svg>
    );
}

/** Whether this registry has Sign in with GitHub set up (`auth.providers.github`). */
export function useGithubSignIn() {
    return Boolean(usePage().props.auth?.providers?.github);
}

/**
 * "Continue with GitHub" under an "or" rule, shown only when the registry has GitHub set up.
 * A plain link: the OAuth round trip is a full page navigation, not an Inertia visit.
 */
export default function GithubButton({ label = 'Continue with GitHub' }) {
    if (!useGithubSignIn()) {
        return null;
    }
    return (
        <>
            <div className="row small muted" style={{ gap: 12 }} aria-hidden="true">
                <span className="grow" style={{ borderTop: '1px solid var(--line)' }} />
                or
                <span className="grow" style={{ borderTop: '1px solid var(--line)' }} />
            </div>
            <a href="/auth/github" className="btn btn-lg" data-github-sign-in>
                <GithubMark />
                {label}
            </a>
        </>
    );
}
