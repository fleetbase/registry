import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import InstanceCodeCard from './InstanceCodeCard';
import { ApiError, api } from '../../lib/api';

vi.mock('../../lib/api', async () => {
    const actual = await vi.importActual('../../lib/api');
    return { ApiError: actual.ApiError, api: { post: vi.fn() } };
});

const organization = { id: 'org_acme', slug: 'acme', name: 'Acme Logistics', role: 'owner' };

function renderCard() {
    return render(<InstanceCodeCard organization={organization} />);
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
        api.post.mockRejectedValue(new ApiError(403, { errors: ['Only organization owners and admins can register instances.'] }));
        renderCard();

        await user.click(screen.getByRole('button', { name: /Create registration code/ }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Only organization owners and admins can register instances.');
        expect(screen.queryByText(/flb instance register/)).not.toBeInTheDocument();
    });
});
