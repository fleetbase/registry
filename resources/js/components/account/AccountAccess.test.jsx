import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import ForgotPassword from '../../pages/Auth/ForgotPassword';
import SignIn from '../../pages/Auth/SignIn';
import Register from '../../pages/Auth/Register';
import Profile from '../../pages/Account/Profile';
import { passwordStrength } from './password';
import { api, ApiError } from '../../lib/api';

// Typing passwords key by key is slow under jsdom.
vi.setConfig({ testTimeout: 20000 });

let page;

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
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

vi.mock('../../lib/api', async () => {
    const actual = await vi.importActual('../../lib/api');
    return { ApiError: actual.ApiError, api: { post: vi.fn(), patch: vi.fn(), delete: vi.fn() } };
});

const account = {
    id: 'acct_1',
    name: 'Pat Publisher',
    email: 'pat@example.test',
    username: 'pat',
    timezone: 'UTC',
    github_login: null,
    has_password: true,
    is_staff: false,
    organizations: [],
};

function setPage({ signedIn = null, github = false } = {}) {
    page = { url: '/', props: { auth: { account: signedIn, providers: { github } }, flash: null, registry: { url: 'https://registry.example.test', host: 'registry.example.test' } } };
}

beforeEach(() => {
    vi.clearAllMocks();
    setPage();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Forgot password', () => {
    it('sends a code, then resets with the code and a new password and goes to sign in', async () => {
        const user = userEvent.setup();
        api.post.mockResolvedValue({});
        render(<ForgotPassword />);

        expect(screen.getByRole('heading', { name: 'Reset your password' })).toBeInTheDocument();
        await user.type(screen.getByLabelText('Email'), ' pat@example.test ');
        await user.click(screen.getByRole('button', { name: 'Send code' }));
        expect(api.post).toHaveBeenCalledWith('auth/password/forgot', { email: 'pat@example.test' });

        // Step 2: the code, the new password and its strength, and the resend countdown.
        expect(screen.getByRole('heading', { name: 'Check your email' })).toBeInTheDocument();
        expect(screen.getByText('pat@example.test')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Resend in 1:00' })).toHaveAttribute('aria-disabled', 'true');

        await user.click(screen.getByRole('button', { name: 'Set new password' }));
        expect(screen.getByText('Enter all six digits.')).toBeInTheDocument();
        expect(api.post).toHaveBeenCalledTimes(1);

        await user.click(screen.getByLabelText('Digit 1 of 6'));
        await user.paste('123456');
        await waitFor(() => expect(screen.getByLabelText('New password')).toHaveFocus());

        await user.type(screen.getByLabelText('New password'), 'short');
        expect(screen.getByText('At least 8 characters with letters and numbers.')).toBeInTheDocument();
        await user.clear(screen.getByLabelText('New password'));
        await user.type(screen.getByLabelText('New password'), 'new-pass-123');
        await user.type(screen.getByLabelText('Repeat new password'), 'new-pass-124');
        await user.click(screen.getByRole('button', { name: 'Set new password' }));
        expect(screen.getByText('The new passwords don’t match.')).toBeInTheDocument();
        expect(api.post).toHaveBeenCalledTimes(1);

        await user.clear(screen.getByLabelText('Repeat new password'));
        await user.type(screen.getByLabelText('Repeat new password'), 'new-pass-123');
        await user.click(screen.getByRole('button', { name: 'Set new password' }));

        expect(api.post).toHaveBeenLastCalledWith('auth/password/reset', { email: 'pat@example.test', code: '123456', password: 'new-pass-123', password_confirmation: 'new-pass-123' });
        expect(router.visit).toHaveBeenCalledWith('/sign-in?email=pat%40example.test&reset=1');
    });

    it('shows a wrong or expired code on the code boxes', async () => {
        const user = userEvent.setup();
        api.post.mockRejectedValue(new ApiError(422, { errors: ['That code is wrong or has expired.'], fields: { code: ['That code is wrong or has expired.'] } }));
        render(<ForgotPassword email="pat@example.test" sent />);

        await user.click(screen.getByLabelText('Digit 1 of 6'));
        await user.paste('654321');
        await user.type(screen.getByLabelText('New password'), 'new-pass-123');
        await user.type(screen.getByLabelText('Repeat new password'), 'new-pass-123');
        await user.click(screen.getByRole('button', { name: 'Set new password' }));

        expect(screen.getByRole('alert')).toHaveTextContent('That code is wrong or expired. Try again or send a new one.');
        expect(screen.getByLabelText('Digit 1 of 6')).toHaveAttribute('aria-invalid', 'true');
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('lets the code be resent after 60 seconds, and goes back to change the email', () => {
        vi.useFakeTimers();
        api.post.mockResolvedValue({});
        render(<ForgotPassword email="pat@example.test" sent />);

        const resend = screen.getByRole('button', { name: 'Resend in 1:00' });
        fireEvent.click(resend);
        expect(api.post).not.toHaveBeenCalled();

        for (let second = 0; second < 60; second++) {
            act(() => vi.advanceTimersByTime(1000));
        }
        expect(resend).toHaveTextContent('Resend code');
        expect(resend).toHaveAttribute('aria-disabled', 'false');
        fireEvent.click(resend);
        expect(api.post).toHaveBeenCalledWith('auth/password/forgot', { email: 'pat@example.test' });
        expect(resend).toHaveTextContent('Resend in 1:00');

        fireEvent.click(screen.getByRole('button', { name: 'Use a different email' }));
        expect(screen.getByLabelText('Email')).toHaveValue('pat@example.test');
        expect(screen.getByRole('button', { name: 'Send code' })).toBeInTheDocument();
    });
});

describe('Sign in and create account', () => {
    it('links Forgot? with the typed email and shows GitHub only when it is set up', async () => {
        const user = userEvent.setup();
        const { unmount } = render(<SignIn />);

        expect(screen.getByRole('link', { name: 'Forgot?' })).toHaveAttribute('href', '/forgot-password');
        await user.type(screen.getByLabelText('Email'), 'pat@example.test');
        expect(screen.getByRole('link', { name: 'Forgot?' })).toHaveAttribute('href', '/forgot-password?email=pat%40example.test');
        expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
        expect(screen.queryByRole('link', { name: /Continue with GitHub/ })).toBeNull();
        unmount();

        setPage({ github: true });
        render(<SignIn reset email="pat@example.test" />);
        expect(screen.getByRole('link', { name: /Continue with GitHub/ })).toHaveAttribute('href', '/auth/github');
        expect(screen.getByText('Password changed. Sign in with the new one.')).toBeInTheDocument();
    });

    it('offers GitHub on create account when it is set up', () => {
        setPage({ github: true });
        render(<Register />);
        expect(screen.getByRole('link', { name: /Continue with GitHub/ })).toHaveAttribute('href', '/auth/github');
    });

    it('rates passwords by the registry rule', () => {
        expect(passwordStrength('').score).toBe(0);
        expect(passwordStrength('abcdefgh').score).toBe(1);
        expect(passwordStrength('abcdefg1').score).toBe(2);
        expect(passwordStrength('Abcdefghijk1!').score).toBe(4);
    });
});

describe('Profile: GitHub', () => {
    it('connects GitHub when it is not connected, and hides the card when GitHub is not set up', () => {
        setPage({ signedIn: account, github: true });
        const { unmount } = render(<Profile />);
        const card = screen.getByRole('region', { name: 'GitHub' });
        expect(within(card).getByText('Not connected')).toBeInTheDocument();
        expect(within(card).getByRole('link', { name: /Connect GitHub/ })).toHaveAttribute('href', '/auth/github');
        unmount();

        setPage({ signedIn: account, github: false });
        render(<Profile />);
        expect(screen.queryByRole('region', { name: 'GitHub' })).toBeNull();
    });

    it('disconnects after confirming', async () => {
        const user = userEvent.setup();
        api.delete.mockResolvedValue({});
        setPage({ signedIn: { ...account, github_login: 'patdev' }, github: true });
        render(<Profile />);

        const card = screen.getByRole('region', { name: 'GitHub' });
        expect(within(card).getByRole('link', { name: '@patdev' })).toHaveAttribute('href', 'https://github.com/patdev');
        await user.click(within(card).getByRole('button', { name: 'Disconnect' }));
        const dialog = screen.getByRole('dialog', { name: 'Disconnect GitHub?' });
        await user.click(within(dialog).getByRole('button', { name: 'Disconnect' }));

        expect(api.delete).toHaveBeenCalledWith('account/github');
        expect(router.reload).toHaveBeenCalled();
    });

    it('explains that a GitHub-only account needs a password first', async () => {
        const user = userEvent.setup();
        api.delete.mockRejectedValue(new ApiError(422, { errors: ['Set a password before disconnecting GitHub.'], code: 'password_required' }));
        setPage({ signedIn: { ...account, github_login: 'patdev', has_password: false }, github: false });
        render(<Profile />);

        // No current password to change: the password column points to Forgot password.
        expect(screen.getByRole('link', { name: 'Set a password' })).toHaveAttribute('href', '/forgot-password?email=pat%40example.test');
        expect(screen.queryByLabelText('Current password')).toBeNull();

        const card = screen.getByRole('region', { name: 'GitHub' });
        await user.click(within(card).getByRole('button', { name: 'Disconnect' }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Disconnect' }));

        expect(within(card).getByRole('alert')).toHaveTextContent('Set a password before disconnecting GitHub.');
        expect(within(card).getByRole('link', { name: 'Set a password' })).toBeInTheDocument();
        expect(router.reload).not.toHaveBeenCalled();
    });
});
