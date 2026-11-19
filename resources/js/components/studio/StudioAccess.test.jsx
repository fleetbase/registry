import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

const { router, page, api } = vi.hoisted(() => ({
    router: { reload: vi.fn(), visit: vi.fn(), replace: vi.fn(), on: vi.fn(() => () => {}) },
    page: { props: {} },
    api: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), upload: vi.fn() },
}));

vi.setConfig({ testTimeout: 30000 });

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => page,
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));
vi.mock('../../layouts/SiteLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../lib/api', () => ({ api }));

import StudioAccess from '../../pages/Studio/Access';
import StudioCreate from '../../pages/Studio/Create';
import { grantBody, grantDraft, parseInstanceIds, seatsLabel, workflowSnippet } from './access';
import { parseRefs } from './TrustedPublishersSection';

const organization = { id: 'org_acme', slug: 'acme', name: 'Acme Logistics' };
const extension = {
    id: 'ext_1',
    slug: 'client-tools',
    name: 'Client Tools',
    status: 'published',
    visibility: 'private',
    pending_visibility: null,
    organization,
    releases: [{ id: 'rel_1', version: '1.0.0', status: 'published', created_at: '2026-10-01T00:00:00Z' }],
};
const instance = (id, name, extra = {}) => ({ id, name, host: `https://${name.toLowerCase().replace(/\s+/g, '-')}.example`, kind: 'self_hosted', status: 'active', ...extra });
const grant = (id, org, extra = {}) => ({
    id,
    organization: org,
    status: 'active',
    instances: [],
    max_activations: null,
    seats_used: 0,
    activations: [],
    note: null,
    expires_at: null,
    revoked_at: null,
    created_at: '2026-10-02T00:00:00Z',
    ...extra,
});
const globex = { id: 'org_globex', slug: 'globex', name: 'Globex' };
const initech = { id: 'org_initech', slug: 'initech', name: 'Initech' };
const umbrella = { id: 'org_umb', slug: 'umbrella', name: 'Umbrella' };

const grants = [
    grant('lic_all', globex, { seats_used: 2, max_activations: 5, note: 'MSA 2026', activations: [{ instance: instance('inst_g1', 'Globex HQ'), release: '1.0.0', activated_at: '2026-10-03T00:00:00Z' }] }),
    grant('lic_some', initech, { instances: [instance('inst_i1', 'Initech Prod')], seats_used: 1, max_activations: 1, expires_at: '2027-01-31T00:00:00Z' }),
    grant('lic_gone', umbrella, { status: 'revoked', revoked_at: '2026-10-10T00:00:00Z' }),
];
const access = (extra = {}) => ({ extension: { id: 'ext_1', slug: 'client-tools', name: 'Client Tools' }, visibility: 'private', pending_visibility: null, grants, ...extra });
const trusted = [{ id: 'tp_1', provider: 'github', repository: 'acme/client-tools', workflow: 'release.yml', environment: null, refs: ['refs/tags/v*'], created_by: { name: 'Pat' }, last_used_at: null, created_at: '2026-10-05T00:00:00Z' }];

function renderPage(props = {}) {
    return render(<StudioAccess extension={extension} access={access()} trusted_publishers={trusted} {...props} />);
}

beforeEach(() => {
    page.props = { auth: { account: { organizations: [{ ...organization, role: 'owner', is_personal: false }] } } };
    Object.values(api).forEach((fn) => fn.mockReset());
    Object.values(router).forEach((fn) => fn.mockClear?.());
});

describe('access helpers', () => {
    it('builds grant bodies: a new grant leaves out empty values, an update sends every field', () => {
        expect(grantBody({ organization: ' acme ', scope: 'all', instances: ['inst_1'], seats: '', expires: '', note: ' ' })).toEqual({ organization: 'acme' });
        expect(grantBody({ organization: 'acme', scope: 'some', instances: ['inst_1'], seats: '3', expires: '2027-01-01', note: 'Pilot' })).toEqual({ organization: 'acme', instances: ['inst_1'], max_activations: 3, expires_at: '2027-01-01', note: 'Pilot' });
        expect(grantBody({ scope: 'all', instances: ['inst_1'], seats: '', expires: '', note: '' }, { create: false })).toEqual({ instances: [], max_activations: null, expires_at: null, note: null });
        expect(grantDraft(grants[1])).toMatchObject({ scope: 'some', instances: ['inst_i1'], seats: '1', expires: '2027-01-31' });
        expect(parseInstanceIds('inst_a, inst_b\ninst_a  inst_c')).toEqual(['inst_a', 'inst_b', 'inst_c']);
        expect(parseRefs('refs/tags/v*, refs/heads/main')).toEqual(['refs/tags/v*', 'refs/heads/main']);
        expect(seatsLabel(grants[0])).toBe('2 of 5');
        expect(seatsLabel(grants[2])).toBe('0 · no limit');
    });

    it('writes a workflow with an OIDC permission and the CLI action', () => {
        const yaml = workflowSnippet({ workflow: 'publish.yml', refs: ['refs/tags/v*', 'refs/heads/main'], environment: 'release' });
        expect(yaml).toContain('# .github/workflows/publish.yml');
        expect(yaml).toContain("    tags: ['v*']");
        expect(yaml).toContain("    branches: ['main']");
        expect(yaml).toContain('  id-token: write');
        expect(yaml).toContain('    environment: release');
        expect(yaml).toContain('uses: fleetbase/fleetbase-cli/action@v2');
    });
});

describe('Studio access page', () => {
    it('lists grants: all instances or the chosen ones, seats, expiry, and revoked grants dimmed', () => {
        const { container } = renderPage();
        const rows = container.querySelectorAll('[data-grant]');
        expect([...rows].map((row) => row.dataset.grant)).toEqual(['lic_all', 'lic_some', 'lic_gone']);

        const all = container.querySelector('[data-grant="lic_all"]');
        expect(within(all).getByText('Globex')).toBeInTheDocument();
        expect(within(all).getByText('All instances')).toBeInTheDocument();
        expect(all.querySelector('[data-seats]')).toHaveTextContent('2 of 5');
        expect(all.querySelector('[data-note]')).toHaveTextContent('MSA 2026');
        expect(within(all).getByText('Never')).toBeInTheDocument();

        const some = container.querySelector('[data-grant="lic_some"]');
        expect(within(some).queryByText('All instances')).toBeNull();
        expect(some.querySelector('[data-instances]')).toHaveTextContent('Initech Prod');
        expect(some.querySelector('[data-instances]')).toHaveTextContent('inst_i1');
        expect(some).toHaveTextContent('31 Jan 2027');

        const gone = container.querySelector('[data-grant="lic_gone"]');
        expect(gone).toHaveClass('sd-dim');
        expect(gone).toHaveTextContent('Revoked');
        expect(within(gone).queryByRole('button', { name: /revoke/i })).toBeNull();
        expect(screen.getByText('2 organizations can install it')).toBeInTheDocument();
    });

    it('revokes after confirming, with the reason', async () => {
        api.delete.mockResolvedValue({ ...grants[0], status: 'revoked' });
        renderPage();
        fireEvent.click(screen.getByRole('button', { name: 'Revoke access for Globex' }));
        const dialog = screen.getByRole('dialog', { name: 'Revoke access for Globex?' });
        expect(dialog).toHaveTextContent('2 seats in use are released');
        expect(api.delete).not.toHaveBeenCalled();

        fireEvent.change(within(dialog).getByLabelText(/reason/i), { target: { value: 'Contract ended' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke access' }));
        await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/publisher/extensions/client-tools/access/lic_all', { reason: 'Contract ended' }));
        await waitFor(() => expect(router.reload).toHaveBeenCalledWith({ only: ['access'] }));
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('grants access: finds the organization, picks instances, posts the body', async () => {
        api.get.mockResolvedValue({ id: 'org_new', slug: 'hooli', name: 'Hooli', instances: [instance('inst_h1', 'Hooli East'), instance('inst_h2', 'Hooli West', { kind: 'cloud' })] });
        api.post.mockResolvedValue(grant('lic_new', { id: 'org_new', slug: 'hooli', name: 'Hooli' }));
        renderPage();

        fireEvent.click(screen.getByRole('button', { name: 'Grant access' }));
        const dialog = screen.getByRole('dialog', { name: 'Grant access to Client Tools' });
        const submit = within(dialog).getByRole('button', { name: 'Grant access' });
        expect(submit).toBeDisabled();

        fireEvent.change(within(dialog).getByLabelText('Organization'), { target: { value: 'hooli' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Find' }));
        await within(dialog).findByText('Hooli');
        expect(api.get).toHaveBeenCalledWith('/publisher/access/organizations/hooli');

        fireEvent.click(within(dialog).getByRole('radio', { name: 'Only some' }));
        expect(submit).toBeDisabled();
        fireEvent.click(within(dialog).getByRole('checkbox', { name: /Hooli West/ }));
        fireEvent.change(within(dialog).getByLabelText(/seats/i), { target: { value: '2' } });
        fireEvent.change(within(dialog).getByLabelText(/expires/i), { target: { value: '2027-06-30' } });
        fireEvent.change(within(dialog).getByLabelText(/note/i), { target: { value: 'Pilot' } });
        fireEvent.click(submit);

        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith('/publisher/extensions/client-tools/access', { organization: 'hooli', instances: ['inst_h2'], max_activations: 2, expires_at: '2027-06-30', note: 'Pilot' }),
        );
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(router.reload).toHaveBeenCalledWith({ only: ['access'] });
    });

    it('takes instance ids when it cannot list the organization’s instances, and shows field errors', async () => {
        api.get.mockResolvedValue({ id: 'org_new', slug: 'hooli', name: 'Hooli', instances: null });
        const error = Object.assign(new Error('The given data was invalid.'), { status: 422, fields: { 'instances.1': ['No instance inst_bad in Hooli.'] } });
        error.field = (name) => error.fields[name]?.[0] ?? null;
        api.post.mockRejectedValue(error);
        renderPage();

        fireEvent.click(screen.getByRole('button', { name: 'Grant access' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Organization'), { target: { value: 'hooli' } });
        fireEvent.keyDown(within(dialog).getByLabelText('Organization'), { key: 'Enter' });
        await within(dialog).findByText('Hooli');
        fireEvent.click(within(dialog).getByRole('radio', { name: 'Only some' }));
        fireEvent.change(within(dialog).getByLabelText('Instance IDs'), { target: { value: 'inst_ok, inst_bad' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Grant access' }));

        await within(dialog).findByText('No instance inst_bad in Hooli.');
        expect(api.post).toHaveBeenCalledWith('/publisher/extensions/client-tools/access', { organization: 'hooli', instances: ['inst_ok', 'inst_bad'] });
    });

    it('says when no organization matches', async () => {
        api.get.mockRejectedValue(Object.assign(new Error('Organization not found.'), { status: 404 }));
        renderPage();
        fireEvent.click(screen.getByRole('button', { name: 'Grant access' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Organization'), { target: { value: 'nobody' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Find' }));
        await within(dialog).findByText('No organization has that slug or ID.');
    });

    it('edits a grant: back to every instance, unlimited seats', async () => {
        api.get.mockResolvedValue({ ...initech, instances: [instance('inst_i1', 'Initech Prod'), instance('inst_i2', 'Initech Lab')] });
        api.patch.mockResolvedValue(grants[1]);
        renderPage();
        fireEvent.click(screen.getByRole('button', { name: 'Edit access for Initech' }));
        const dialog = screen.getByRole('dialog', { name: 'Edit access for Initech' });
        await waitFor(() => expect(within(dialog).getByRole('checkbox', { name: /Initech Prod/ })).toBeChecked());
        expect(api.get).toHaveBeenCalledWith('/publisher/access/organizations/initech');

        fireEvent.click(within(dialog).getByRole('radio', { name: 'All instances' }));
        fireEvent.change(within(dialog).getByLabelText(/seats/i), { target: { value: '' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/publisher/extensions/client-tools/access/lic_some', { instances: [], max_activations: null, expires_at: '2027-01-31', note: null }));
    });

    it('shows a waiting request to go public, and cancels it', async () => {
        api.patch.mockResolvedValue({ ...extension, pending_visibility: null });
        const { container } = renderPage({ extension: { ...extension, pending_visibility: 'public' }, access: access({ pending_visibility: 'public' }) });
        expect(container.querySelector('[data-pending-public]')).toHaveTextContent('Going public with the next reviewed release');
        expect(screen.getByText('Private · going public')).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /private/i })).toHaveAttribute('aria-checked', 'true');

        // Choosing Public again does nothing: it is already requested.
        fireEvent.click(screen.getByRole('radio', { name: /public/i }));
        expect(screen.queryByRole('dialog')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Keep it private' }));
        await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/publisher/extensions/client-tools', { visibility: 'private' }));
        expect(router.reload).toHaveBeenCalledWith({ only: ['extension', 'access'] });
    });

    it('explains what going public means after a release, then asks for it', async () => {
        api.patch.mockResolvedValue({ ...extension, pending_visibility: 'public' });
        renderPage();
        fireEvent.click(screen.getByRole('radio', { name: /public/i }));
        const dialog = screen.getByRole('dialog', { name: 'Ask to make Client Tools public?' });
        expect(dialog).toHaveTextContent('stays private until your next release passes a Fleetbase review');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Go public after review' }));
        await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/publisher/extensions/client-tools', { visibility: 'public' }));
    });

    it('makes a public listing private after confirming', async () => {
        api.patch.mockResolvedValue({ ...extension, visibility: 'private' });
        renderPage({ extension: { ...extension, visibility: 'public' }, access: access({ visibility: 'public', grants: [] }) });
        expect(screen.getByText('No organizations have access yet')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: /private/i }));
        const dialog = screen.getByRole('dialog', { name: 'Make Client Tools private?' });
        expect(dialog).toHaveTextContent('secret scanning included');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Make private' }));
        await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/publisher/extensions/client-tools', { visibility: 'private' }));
    });

    it('adds and removes trusted publishers', async () => {
        api.post.mockResolvedValue({ ...trusted[0], id: 'tp_2', repository: 'acme/other', workflow: 'publish.yml' });
        api.delete.mockResolvedValue('');
        const { container } = renderPage();
        expect(container.querySelector('[data-trusted-publisher="tp_1"]')).toHaveTextContent('acme/client-tools');
        expect(container.querySelector('[data-snippet]')).toHaveTextContent('id-token: write');

        fireEvent.click(screen.getByRole('button', { name: 'Add a workflow' }));
        const form = screen.getByRole('form', { name: 'Add a trusted publisher' });
        fireEvent.change(within(form).getByLabelText('Repository'), { target: { value: 'acme/other' } });
        fireEvent.change(within(form).getByLabelText('Workflow file'), { target: { value: 'publish.yml' } });
        fireEvent.change(within(form).getByLabelText(/environment/i), { target: { value: 'release' } });
        fireEvent.change(within(form).getByLabelText('Refs'), { target: { value: 'refs/tags/v*, refs/heads/main' } });
        expect(container.querySelector('[data-snippet]')).toHaveTextContent('.github/workflows/publish.yml');
        fireEvent.click(within(form).getByRole('button', { name: 'Add trusted publisher' }));
        await waitFor(() =>
            expect(api.post).toHaveBeenCalledWith('/publisher/extensions/client-tools/trusted-publishers', { repository: 'acme/other', workflow: 'publish.yml', environment: 'release', refs: ['refs/tags/v*', 'refs/heads/main'] }),
        );
        await waitFor(() => expect(router.reload).toHaveBeenCalledWith({ only: ['trusted_publishers'] }));

        fireEvent.click(screen.getByRole('button', { name: 'Remove acme/client-tools release.yml' }));
        const dialog = screen.getByRole('dialog', { name: 'Remove acme/client-tools?' });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Remove' }));
        await waitFor(() => expect(api.delete).toHaveBeenCalledWith('/publisher/extensions/client-tools/trusted-publishers/tp_1'));
    });

    it('shows trusted publisher field errors', async () => {
        const error = Object.assign(new Error('The given data was invalid.'), { status: 422, fields: { workflow: ['The workflow is the file name under .github/workflows, e.g. release.yml.'] } });
        error.field = (name) => error.fields[name]?.[0] ?? null;
        api.post.mockRejectedValue(error);
        renderPage({ trusted_publishers: [] });
        const form = screen.getByRole('form', { name: 'Add a trusted publisher' });
        fireEvent.change(within(form).getByLabelText('Repository'), { target: { value: 'acme/x' } });
        fireEvent.change(within(form).getByLabelText('Workflow file'), { target: { value: '.github/workflows/release.yml' } });
        fireEvent.click(within(form).getByRole('button', { name: 'Add trusted publisher' }));
        await within(form).findByText(/file name under .github\/workflows/);
    });

    it('tells publishers without the owner or admin role who can manage access', () => {
        const { container } = renderPage({ access: null, trusted_publishers: [], forbidden: 'Only organization owners and admins can change who can see or publish this extension.' });
        expect(container.querySelector('[data-forbidden]')).toHaveTextContent('Only owners and admins can manage access');
        expect(container.querySelector('[data-grants]')).toBeNull();
        expect(screen.getByRole('link', { name: 'Access' })).toHaveAttribute('aria-current', 'page');
    });
});

describe('create a private extension', () => {
    it('sends visibility private when ticked', async () => {
        api.get.mockResolvedValue({ available: true });
        api.post.mockResolvedValue({ slug: 'client-tools' });
        render(<StudioCreate categories={[]} />);
        fireEvent.change(screen.getByLabelText('Extension name'), { target: { value: 'Client Tools' } });
        fireEvent.click(screen.getByRole('checkbox', { name: /private extension/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Create draft' }));
        await waitFor(() => expect(api.post).toHaveBeenCalledWith('/publisher/extensions', expect.objectContaining({ name: 'Client Tools', visibility: 'private' })));
    });
});
