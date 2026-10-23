import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import InstanceCodeCard from './InstanceCodeCard';
import { ToastProvider } from '../Toasts';
import { api } from '../../lib/api';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { flash: null } }) }));
vi.mock('../../lib/api', () => ({ api: { post: vi.fn() } }));

const organization = { id: 'org_acme', slug: 'acme', name: 'Acme Logistics', role: 'owner' };

function renderCard() {
    return render(
        <ToastProvider>
            <InstanceCodeCard organization={organization} />
        </ToastProvider>,
    );
}

describe('InstanceCodeCard', () => {
    beforeEach(() => api.post.mockReset());

    it('mints a registration code for the organization and shows how to use it', async () => {
        const user = userEvent.setup();
        api.post.mockResolvedValue({ code: 'K7Q2-M9PD', organization: 'org_acme', expires_at: '2026-10-23T12:15:00Z', expires_in: 900 });
        renderCard();

        await user.click(screen.getByRole('button', { name: /Create registration code/ }));

        expect(api.post).toHaveBeenCalledWith('account/instance-codes', { organization: 'acme' });
        expect(await screen.findByText('K7Q2-M9PD')).toBeInTheDocument();
        expect(screen.getByText('flb instance register --path /path/to/fleetbase --code K7Q2-M9PD')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /New code/ })).toBeInTheDocument();
    });

    it('shows the service refusal', async () => {
        const user = userEvent.setup();
        api.post.mockRejectedValue(new Error('Only organization owners and admins can register instances.'));
        renderCard();

        await user.click(screen.getByRole('button', { name: /Create registration code/ }));

        expect(await screen.findByText('Only organization owners and admins can register instances.')).toBeInTheDocument();
        expect(screen.queryByText(/flb instance register/)).not.toBeInTheDocument();
    });
});
