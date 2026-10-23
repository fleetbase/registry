import { useEffect, useRef, useState } from 'react';
import { StatusChip } from '../Status';
import { api } from '../../lib/api';

export const KINDS = ['npm', 'composer'];

/** The query for `GET /publisher/packages/availability`, or null when there is nothing to check. */
export function availabilityQuery(organization, packages) {
    if (!organization) {
        return null;
    }
    const names = {};
    for (const kind of KINDS) {
        const name = String(packages?.[kind] ?? '').trim();
        if (name) {
            names[kind] = name;
        }
    }
    return Object.keys(names).length ? { organization, ...names } : null;
}

/**
 * Whether the organization can claim the package names, checked `delay` ms after they stop
 * changing. Returns `{npm, composer}`, each `{state: 'idle'|'checking'|'available'|'taken', reason}`.
 * Answers to names that have since changed are dropped.
 */
export function usePackageAvailability(organization, packages, { enabled = true, delay = 300 } = {}) {
    const query = enabled ? availabilityQuery(organization, packages) : null;
    const key = query ? JSON.stringify(query) : null;
    const [answer, setAnswer] = useState({ key: null, data: null });
    const latest = useRef(null);

    useEffect(() => {
        latest.current = key;
        if (!key) {
            return undefined;
        }
        const timer = setTimeout(async () => {
            try {
                const data = await api.get('/publisher/packages/availability', JSON.parse(key));
                if (latest.current === key) {
                    setAnswer({ key, data });
                }
            } catch {
                // A failed check says nothing; creating the listing still validates the names.
                if (latest.current === key) {
                    setAnswer({ key, data: {} });
                }
            }
        }, delay);
        return () => clearTimeout(timer);
    }, [key, delay]);

    const result = {};
    for (const kind of KINDS) {
        if (!query?.[kind]) {
            result[kind] = { state: 'idle', reason: null };
        } else if (answer.key !== key) {
            result[kind] = { state: 'checking', reason: null };
        } else {
            const entry = answer.data?.[kind];
            result[kind] = entry ? { state: entry.available ? 'available' : 'taken', reason: entry.reason ?? null } : { state: 'idle', reason: null };
        }
    }
    return result;
}

/** "Available", "Not available" or "Checking", with the reason a name cannot be claimed. */
export function AvailabilityChip({ result }) {
    if (result.state === 'checking') {
        return (
            <StatusChip tone="idle" icon="spinner" data-availability="checking">
                Checking
            </StatusChip>
        );
    }
    if (result.state === 'available') {
        return (
            <StatusChip tone="ok" icon="check" data-availability="available">
                Available
            </StatusChip>
        );
    }
    if (result.state === 'taken') {
        return (
            <StatusChip tone="fail" icon="x" data-availability="taken">
                Not available
            </StatusChip>
        );
    }
    return null;
}

/** The chip and, when the name cannot be claimed, why; announced politely as it changes. */
export function AvailabilityLine({ result, ...rest }) {
    return (
        <span className="row wrapflex" style={{ gap: 8 }} aria-live="polite" {...rest}>
            <AvailabilityChip result={result} />
            {result.state === 'taken' && result.reason && <span className="small ink2">{result.reason}</span>}
        </span>
    );
}
