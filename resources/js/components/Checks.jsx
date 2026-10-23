import Icon from './Icon';
import { CHECKS } from '../lib/statuses';

const LOOK = {
    ok: { icon: 'check', word: 'Passed', color: 'var(--ok)' },
    fail: { icon: 'x', word: 'Failed', color: 'var(--fail)' },
    warn: { icon: 'alert', word: 'Warning', color: 'var(--you)' },
    run: { icon: 'spinner', word: 'Running', color: 'var(--wait)' },
    wait: { icon: null, word: 'Waiting', color: 'var(--muted)' },
};

/** The rows for a release's checks, in the service's order. Unreported checks run or wait. */
export function checkRows(release) {
    const reported = new Map((release?.checks ?? []).map((check) => [check.check, check]));
    const running = release?.status === 'checking';
    let firstPending = true;
    return Object.entries(CHECKS).map(([key, meta]) => {
        const check = reported.get(key);
        let state = 'wait';
        if (check) {
            state = check.passed ? (check.warnings?.length ? 'warn' : 'ok') : 'fail';
        } else if (running && firstPending) {
            state = 'run';
            firstPending = false;
        }
        return { key, ...meta, check, state };
    });
}

export function checksSummary(release) {
    const rows = checkRows(release);
    const failed = rows.filter((row) => row.state === 'fail').length;
    const warned = rows.filter((row) => row.state === 'warn').length;
    const done = rows.filter((row) => row.check).length;
    if (release?.status === 'checking') {
        return { tone: 'wait', label: `${done} of ${rows.length} done` };
    }
    if (failed) {
        return { tone: 'fail', label: `${failed} failed${warned ? `, ${warned} ${warned === 1 ? 'warning' : 'warnings'}` : ''}` };
    }
    if (!done) {
        return { tone: 'idle', label: 'Not run yet' };
    }
    return { tone: 'ok', label: warned ? `All passed, ${warned} ${warned === 1 ? 'warning' : 'warnings'}` : 'All passed' };
}

/** The eight check rows with their messages (errors always, warnings when `showWarnings`). */
export default function Checks({ release, showWarnings = true }) {
    return (
        <div className="checks" aria-live="polite">
            {checkRows(release).map((row) => {
                const look = LOOK[row.state];
                return (
                    <div key={row.key} className={`ck ${row.state}`} data-check={row.key} data-state={row.state}>
                        <span className="ck-dot">{look.icon && <Icon name={look.icon} strokeWidth={2.6} />}</span>
                        <div>
                            <div className="ck-title">{row.label}</div>
                            <div className="ck-desc">{row.check?.summary || row.description}</div>
                            {row.check?.errors?.map((message, index) => (
                                <div key={`e${index}`} className="ck-msg">
                                    {message}
                                </div>
                            ))}
                            {showWarnings &&
                                row.check?.passed &&
                                row.check?.warnings?.map((message, index) => (
                                    <div key={`w${index}`} className="ck-msg">
                                        {message}
                                    </div>
                                ))}
                        </div>
                        <span className="ck-state" style={{ color: look.color }}>
                            {look.word}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
