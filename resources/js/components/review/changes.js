/**
 * What changed since the live version a release updates (`release.previous` from the admin
 * API), kept pure so it can be tested without rendering.
 */

/**
 * JSON with object keys and list items sorted, so order never counts as a change (as in the
 * server's review policy).
 */
export function canonical(value) {
    if (Array.isArray(value)) {
        return `[${value.map(canonical).sort().join(',')}]`;
    }
    if (value && typeof value === 'object') {
        return `{${Object.keys(value)
            .sort()
            .map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`)
            .join(',')}}`;
    }
    return JSON.stringify(value ?? null);
}

/** Fields that change on every release, so listing them says nothing. */
const ALWAYS_CHANGES = new Set(['version']);

/**
 * Top-level manifest fields added, removed or changed: `[{ key, change, before, after }]`,
 * in key order. `version` is left out.
 */
export function manifestChanges(before = {}, after = {}) {
    const previous = before ?? {};
    const current = after ?? {};
    const keys = [...new Set([...Object.keys(previous), ...Object.keys(current)])].filter((key) => !ALWAYS_CHANGES.has(key)).sort();

    return keys.flatMap((key) => {
        const had = Object.prototype.hasOwnProperty.call(previous, key);
        const has = Object.prototype.hasOwnProperty.call(current, key);
        if (had && has && canonical(previous[key]) === canonical(current[key])) {
            return [];
        }
        return [{ key, change: had && has ? 'changed' : has ? 'added' : 'removed', before: previous[key], after: current[key] }];
    });
}

/** Compatibility constraints that differ: `[{ key, before, after }]` (null when absent). */
export function compatChanges(before = {}, after = {}) {
    const previous = before ?? {};
    const current = after ?? {};
    return [...new Set([...Object.keys(previous), ...Object.keys(current)])]
        .sort()
        .filter((key) => canonical(previous[key]) !== canonical(current[key]))
        .map((key) => ({ key, before: previous[key] ?? null, after: current[key] ?? null }));
}

/** A short, single-line rendering of a manifest value for the before / after columns. */
export function preview(value, max = 120) {
    if (value === undefined) {
        return '—';
    }
    const text = typeof value === 'string' ? value : JSON.stringify(value);
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

/**
 * Everything the evidence tabs say about the update, or null for a first release:
 * `{ version, publishedAt, compat, manifest, sizeBefore, sizeAfter }`.
 */
export function releaseChanges(release) {
    const previous = release?.previous;
    if (!previous?.version) {
        return null;
    }
    return {
        version: previous.version,
        publishedAt: previous.published_at ?? null,
        compat: compatChanges(previous.compat, release.compat),
        manifest: manifestChanges(previous.manifest, release.manifest),
        sizeBefore: previous.size_bytes ?? null,
        sizeAfter: release.size_bytes ?? null,
    };
}
