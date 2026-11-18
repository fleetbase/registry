/**
 * Helpers for Studio → Access: who can see a private extension (grants), its visibility and
 * the GitHub workflows trusted to publish it.
 */

/** Release statuses that mean something was released (going public then waits for review). */
const RELEASED = ['published', 'yanked'];

/** Whether the listing has had a release go out (published or later yanked). */
export function hasReleased(extension) {
    return (extension?.releases ?? []).some((release) => RELEASED.includes(release.status));
}

/** `inst_a, inst_b\ninst_c` → `['inst_a', 'inst_b', 'inst_c']`, without blanks or repeats. */
export function parseInstanceIds(text) {
    return [
        ...new Set(
            String(text ?? '')
                .split(/[\s,]+/)
                .map((id) => id.trim())
                .filter(Boolean),
        ),
    ];
}

/** "3" → 3; empty → null (unlimited); anything else → NaN, which the form refuses. */
export function parseSeats(value) {
    const text = String(value ?? '').trim();
    if (text === '') {
        return null;
    }
    return /^\d+$/.test(text) && Number(text) > 0 ? Number(text) : Number.NaN;
}

/**
 * The grant form as the API body. `scope` is `all` (every instance of the organization) or
 * `some` (only `instances`). For a new grant (`create`) empty values are left out; an update
 * sends every field, so clearing one clears it on the grant.
 */
export function grantBody({ organization, scope = 'all', instances = [], seats = '', expires = '', note = '' }, { create = true } = {}) {
    const limited = scope === 'some' ? instances : [];
    const body = {
        instances: limited,
        max_activations: parseSeats(seats),
        expires_at: expires || null,
        note: note.trim() || null,
    };
    if (!create) {
        return body;
    }
    const created = { organization: String(organization ?? '').trim() };
    if (limited.length) created.instances = limited;
    if (body.max_activations !== null) created.max_activations = body.max_activations;
    if (body.expires_at) created.expires_at = body.expires_at;
    if (body.note) created.note = body.note;
    return created;
}

/** The form's starting values for an existing grant. */
export function grantDraft(grant) {
    const instances = (grant?.instances ?? []).map((instance) => instance.id);
    return {
        organization: grant?.organization?.slug ?? '',
        scope: instances.length ? 'some' : 'all',
        instances,
        seats: grant?.max_activations ? String(grant.max_activations) : '',
        expires: grant?.expires_at ? String(grant.expires_at).slice(0, 10) : '',
        note: grant?.note ?? '',
    };
}

/** "2 of 5", or "2 · no limit". */
export function seatsLabel(grant) {
    const used = grant?.seats_used ?? 0;
    return grant?.max_activations ? `${used} of ${grant.max_activations}` : `${used} · no limit`;
}

/** An instance's label: its name, else its host, else its id. */
export function instanceLabel(instance) {
    return instance?.name || instance?.host || instance?.id || 'Unknown instance';
}

/** Tomorrow as `YYYY-MM-DD` (an expiry must be in the future). */
export function tomorrow(now = new Date()) {
    const next = new Date(now.getTime() + 24 * 60 * 60 * 1000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}`;
}

/** Active grants first, then expired, then revoked; newest first within each. */
export function sortGrants(grants = []) {
    const rank = { active: 0, expired: 1, revoked: 2 };
    return [...grants].sort((a, b) => (rank[a.status] ?? 3) - (rank[b.status] ?? 3) || new Date(b.created_at) - new Date(a.created_at));
}

/** `refs/tags/v*` → the `on.push.tags` (or `branches`) filters of a workflow. */
export function refFilters(refs = []) {
    const tags = refs.filter((ref) => ref.startsWith('refs/tags/')).map((ref) => ref.slice('refs/tags/'.length));
    const branches = refs.filter((ref) => ref.startsWith('refs/heads/')).map((ref) => ref.slice('refs/heads/'.length));
    return { tags, branches };
}

/**
 * A GitHub Actions workflow that publishes with an OIDC token: `id-token: write`, the trigger
 * from the trusted publisher's refs, and the Fleetbase CLI action.
 */
export const DEFAULT_REGISTRY_URL = 'https://registry.fleetbase.io';

export function workflowSnippet({ workflow = 'release.yml', refs = ['refs/tags/v*'], environment = '', registry = '' } = {}) {
    const { tags, branches } = refFilters(refs.length ? refs : ['refs/tags/v*']);
    const list = (values) => `[${values.map((value) => `'${value}'`).join(', ')}]`;
    const lines = [
        `# .github/workflows/${workflow || 'release.yml'}`,
        'name: Publish to the Fleetbase Registry',
        'on:',
        '  push:',
        ...(tags.length ? [`    tags: ${list(tags)}`] : []),
        ...(branches.length ? [`    branches: ${list(branches)}`] : []),
        'permissions:',
        '  contents: read',
        '  id-token: write',
        'jobs:',
        '  publish:',
        '    runs-on: ubuntu-latest',
        ...(environment ? [`    environment: ${environment}`] : []),
        '    steps:',
        '      - uses: actions/checkout@v4',
        '      - uses: fleetbase/fleetbase-cli/action@v2',
        // The action publishes to registry.fleetbase.io unless told otherwise (staging, self-hosted).
        ...(registry && registry.replace(/\/+$/, '') !== DEFAULT_REGISTRY_URL ? ['        with:', `          registry: ${registry.replace(/\/+$/, '')}`] : []),
    ];
    return lines.join('\n');
}
