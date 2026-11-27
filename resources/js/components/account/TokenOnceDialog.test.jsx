import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import TokenOnceDialog from './TokenOnceDialog';
import NewTokenForm from './NewTokenForm';
import { daysLeft, expiryDate, maskToken } from './tokens';
import Tokens from '../../pages/Account/Tokens';
import { api } from '../../lib/api';
import { router } from '@inertiajs/react';

const PLAINTEXT = 'flbp_7Kq2mD9pLsE4vX1nQw8ZbRtY6uHc3FgJk0Xea1b2';

const account = {
    id: 'acct_1',
    name: 'Pat Publisher',
    email: 'pat@example.test',
    is_staff: false,
    organizations: [
        { id: 'org_personal', slug: 'pat', name: 'Pat Publisher', role: 'owner', is_personal: true },
        { id: 'org_acme', slug: 'acme', name: 'Acme Logistics', role: 'owner', is_personal: false },
    ],
};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/account/tokens', props: { auth: { account }, registry: { url: 'https://registry.example.test', host: 'registry.example.test' }, flash: null } }),
    router: { reload: vi.fn(), visit: vi.fn() },
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
}));

vi.mock('../../layouts/SiteLayout', async () => {
    const { ToastProvider } = await import('../Toasts');
    return { default: ({ children }) => <ToastProvider>{children}</ToastProvider> };
});

vi.mock('../../lib/api', () => ({ api: { post: vi.fn(), delete: vi.fn() } }));

const created = {
    token: PLAINTEXT,
    token_type: 'Bearer',
    expires_at: '2027-04-21T00:00:00Z',
    token_details: { id: 'tok_1', name: 'GitHub Actions: publish', masked: 'flbp_…a1b2', scopes: ['publish'], organization: 'org_acme', expires_at: '2027-04-21T00:00:00Z' },
};

beforeEach(() => {
    vi.clearAllMocks();
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue() }, configurable: true });
});

describe('token helpers', () => {
    it('masks a token and counts days left', () => {
        expect(maskToken(PLAINTEXT)).toBe('flbp_7Kq2…a1b2');
        const now = new Date('2026-10-23T12:00:00Z');
        expect(daysLeft('2026-10-29T12:00:00Z', now)).toBe(6);
        expect(daysLeft('2026-10-01T00:00:00Z', now)).toBe(0);
        expect(expiryDate(30, now).toISOString()).toBe('2026-11-22T12:00:00.000Z');
    });
});

describe('TokenOnceDialog', () => {
    it('cannot be dismissed until the token is stored, then closes', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<TokenOnceDialog created={created} organizationName="Acme Logistics" registryUrl="https://registry.example.test" onClose={onClose} />);

        const dialog = screen.getByRole('dialog', { name: 'Copy your token now' });
        expect(within(dialog).getByLabelText('Your new token')).toHaveTextContent(PLAINTEXT);
        expect(within(dialog).queryByRole('button', { name: 'Close' })).toBeNull();
        expect(within(dialog).getByText('Acme Logistics')).toBeInTheDocument();

        const done = within(dialog).getByRole('button', { name: 'Done' });
        expect(done).toHaveAttribute('aria-disabled', 'true');
        await user.click(done);
        fireEvent.keyDown(dialog, { key: 'Escape' });
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent('Tick the box once the token is stored');

        await user.click(screen.getByRole('checkbox', { name: 'I have stored this token somewhere safe' }));
        expect(done).toHaveAttribute('aria-disabled', 'false');
        await user.click(done);
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('hides and reveals the token and copies it and the login command', async () => {
        const user = userEvent.setup();
        const writeText = vi.spyOn(navigator.clipboard, 'writeText');
        render(<TokenOnceDialog created={created} registryUrl="https://registry.example.test" onClose={() => {}} />);

        await user.click(screen.getByRole('button', { name: 'Hide token' }));
        const code = screen.getByLabelText('Your new token');
        expect(code).not.toHaveTextContent(PLAINTEXT);
        expect(code.textContent.endsWith('a1b2')).toBe(true);
        expect(screen.getByText(/flb login --token flbp_7Kq2…a1b2 --registry/)).toBeInTheDocument();
        expect(screen.getByText('All your organizations')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Show token' }));
        expect(screen.getByLabelText('Your new token')).toHaveTextContent(PLAINTEXT);

        await user.click(screen.getByRole('button', { name: /Copy token/ }));
        expect(writeText).toHaveBeenCalledWith(PLAINTEXT);
        await user.click(screen.getByRole('button', { name: 'Copy login command' }));
        expect(writeText).toHaveBeenLastCalledWith(`flb login --token ${PLAINTEXT} --registry https://registry.example.test`);
    });
});

describe('NewTokenForm', () => {
    it('toggles scopes, pins the first shared organization and sends the chosen expiry', async () => {
        const user = userEvent.setup();
        api.post.mockResolvedValue(created);
        const onCreated = vi.fn();
        render(<NewTokenForm organizations={account.organizations} onCreated={onCreated} onCancel={() => {}} />);

        expect(screen.getByRole('button', { name: /Publish/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.queryByRole('button', { name: /Review/ })).toBeNull();
        await user.click(screen.getByRole('button', { name: /Publish/ }));
        await user.type(screen.getByLabelText('Name'), 'CI');
        await user.click(screen.getByRole('button', { name: 'Create token' }));
        expect(screen.getByText('Pick at least one thing it can do.')).toBeInTheDocument();
        expect(api.post).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: /Install/ }));
        await user.click(screen.getByRole('radio', { name: '90 days' }));
        await user.click(screen.getByRole('button', { name: 'Create token' }));

        expect(api.post).toHaveBeenCalledWith('account/tokens', { name: 'CI', scopes: ['install'], expires_in_days: 90, organization: 'org_acme' });
        expect(onCreated).toHaveBeenCalledWith(created);
    });

    it('offers the review scope to staff', () => {
        render(<NewTokenForm organizations={account.organizations} isStaff onCreated={() => {}} onCancel={() => {}} />);
        expect(screen.getByRole('button', { name: /Review/ })).toHaveAttribute('aria-pressed', 'false');
    });
});

describe('Tokens page', () => {
    it('shows a new token once and keeps nothing of it after the dialog closes', async () => {
        const user = userEvent.setup();
        api.post.mockResolvedValue(created);
        const soon = new Date(Date.now() + 6 * 24 * 60 * 60 * 1000 - 3600000).toISOString();
        const tokens = [{ id: 'tok_0', name: 'Prod server install', masked: 'flbp_…p2Lm', scopes: ['install'], organization: 'org_acme', expires_at: soon, last_used_at: null, last_used_ip: null }];
        render(<Tokens tokens={tokens} />);

        expect(screen.getByText('Expires in 6 days')).toBeInTheDocument();
        expect(screen.getByText('Never used')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'New token' }));
        await user.type(screen.getByLabelText('Name'), 'GitHub Actions: publish');
        await user.click(screen.getByRole('button', { name: 'Create token' }));

        expect(router.reload).toHaveBeenCalledWith({ only: ['tokens'] });
        expect(screen.getByLabelText('Your new token')).toHaveTextContent(PLAINTEXT);
        expect(screen.queryByRole('form', { name: 'New token' })).toBeNull();

        await user.click(screen.getByRole('checkbox', { name: 'I have stored this token somewhere safe' }));
        await act(async () => {
            await user.click(screen.getByRole('button', { name: 'Done' }));
        });

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(document.body.innerHTML).not.toContain(PLAINTEXT);
        expect(document.body.innerHTML).not.toContain('7Kq2');
    });

    it('confirms before revoking', async () => {
        const user = userEvent.setup();
        api.delete.mockResolvedValue({ revoked: true });
        const tokens = [{ id: 'tok_0', name: 'Old CI token', masked: 'flbp_…Qz77', scopes: ['publish'], organization: null, expires_at: '2099-01-01T00:00:00Z', last_used_at: null }];
        render(<Tokens tokens={tokens} />);

        expect(screen.getByText('All organizations')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Revoke Old CI token' }));
        expect(api.delete).not.toHaveBeenCalled();
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Revoke token' }));

        expect(api.delete).toHaveBeenCalledWith('account/tokens/tok_0');
        expect(router.reload).toHaveBeenCalledWith({ only: ['tokens'] });
    });
});
