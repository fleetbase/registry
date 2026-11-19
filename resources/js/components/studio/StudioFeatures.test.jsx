import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, createEvent, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

const { router, page, api } = vi.hoisted(() => ({
    router: { reload: vi.fn(), visit: vi.fn(), replace: vi.fn(), on: vi.fn(() => () => {}) },
    page: { props: {} },
    api: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), upload: vi.fn() },
}));

vi.setConfig({ testTimeout: 30000 });

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => page,
    usePoll: () => ({ start: () => {}, stop: () => {} }),
    Link: ({ href, children, ...rest }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));
vi.mock('../../layouts/SiteLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../../lib/api', () => ({ api }));

import StudioCreate from '../../pages/Studio/Create';
import ReleaseNotes from './ReleaseNotes';
import MediaSection from './MediaSection';
import { ToastProvider } from '../Toasts';

const account = { organizations: [{ id: 'o1', slug: 'acme', name: 'Acme Logistics', role: 'owner', is_personal: false }] };

beforeEach(() => {
    page.props = { auth: { account } };
    Object.values(api).forEach((fn) => fn.mockReset());
    Object.values(router).forEach((fn) => fn.mockClear?.());
    router.reload.mockImplementation((options) => options?.onFinish?.());
});

afterEach(() => {
    vi.useRealTimers();
});

describe('package name availability on Create', () => {
    it('checks the suggested names 300 ms after typing stops and shows Available', async () => {
        vi.useFakeTimers();
        api.get.mockResolvedValue({ npm: { name: '@acme/dock-engine', available: true, reason: null }, composer: { name: 'acme/dock-api', available: true, reason: null } });
        const { container } = render(<StudioCreate categories={[]} />);

        // No name yet: nothing to check.
        await act(async () => vi.advanceTimersByTime(400));
        expect(api.get).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Extension name'), { target: { value: 'Do' } });
        fireEvent.change(screen.getByLabelText('Extension name'), { target: { value: 'Dock' } });
        expect(container.querySelector('[data-availability-for="npm"] [data-availability="checking"]')).toBeInTheDocument();
        await act(async () => vi.advanceTimersByTime(299));
        expect(api.get).not.toHaveBeenCalled();
        await act(async () => vi.advanceTimersByTime(1));

        expect(api.get).toHaveBeenCalledTimes(1);
        expect(api.get).toHaveBeenCalledWith('/publisher/packages/availability', { organization: 'acme', npm: '@acme/dock-engine', composer: 'acme/dock-api' });
        expect(within(container.querySelector('[data-availability-for="npm"]')).getByText('Available')).toBeInTheDocument();
        expect(within(container.querySelector('[data-availability-for="composer"]')).getByText('Available')).toBeInTheDocument();
    });

    it('shows a fail chip with the reason, and keeps the server errors on submit', async () => {
        api.get.mockImplementation(async (url, params) => ({
            npm: { name: params.npm, available: params.npm !== '@acme/taken-engine', reason: params.npm === '@acme/taken-engine' ? 'This name is registered to another publisher\'s extension.' : null },
            ...(params.composer ? { composer: { name: params.composer, available: true, reason: null } } : {}),
        }));
        const { container } = render(<StudioCreate categories={[]} />);
        fireEvent.change(screen.getByLabelText('Extension name'), { target: { value: 'Taken' } });

        await waitFor(() => expect(container.querySelector('[data-availability-for="npm"] [data-availability="taken"]')).toBeInTheDocument());
        expect(screen.getByText('Not available')).toBeInTheDocument();
        expect(container.querySelector('[data-availability-reason="npm"]')).toHaveTextContent('This name is registered to another publisher\'s extension.');
        expect(within(container.querySelector('[data-availability-for="composer"]')).getByText('Available')).toBeInTheDocument();

        // Editing a name checks the new one.
        fireEvent.click(screen.getByRole('button', { name: 'Edit names' }));
        fireEvent.change(screen.getByLabelText('npm engine'), { target: { value: '@acme/free-engine' } });
        await waitFor(() => expect(api.get).toHaveBeenLastCalledWith('/publisher/packages/availability', { organization: 'acme', npm: '@acme/free-engine', composer: 'acme/taken-api' }));
        await waitFor(() => expect(within(container.querySelector('[data-availability-for="npm"]')).getByText('Available')).toBeInTheDocument());

        // The server still has the last word.
        const error = Object.assign(new Error('Invalid'), { fields: { 'packages.npm': ['Use a valid npm package name.'] }, field: (key) => (key === 'packages.npm' ? 'Use a valid npm package name.' : null) });
        api.post.mockRejectedValue(error);
        fireEvent.click(screen.getByRole('button', { name: 'Create draft' }));
        expect(await screen.findByText('Use a valid npm package name.')).toBeInTheDocument();
    });
});

describe('editing release notes', () => {
    const release = { id: 'rel_1', version: '0.4.0', status: 'published', notes: 'Templates per customer.' };

    it('edits inline, saves with PATCH and confirms with a toast', async () => {
        api.patch.mockResolvedValue({ ...release, notes: 'Faster slips.' });
        render(
            <ToastProvider>
                <ReleaseNotes release={release} />
            </ToastProvider>
        );
        expect(screen.getByText('Templates per customer.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Edit notes' }));
        const box = screen.getByLabelText('Notes for this version');
        expect(box).toHaveValue('Templates per customer.');
        expect(box).toHaveFocus();
        fireEvent.change(box, { target: { value: 'Faster slips.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save notes' }));

        await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/publisher/releases/rel_1', { notes: 'Faster slips.' }));
        await waitFor(() => expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['release'] })));
        expect(await screen.findByText('Release notes saved.')).toBeInTheDocument();
        expect(screen.queryByLabelText('Notes for this version')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Edit notes' })).toHaveFocus();
    });

    it('cancels without saving and shows field errors', async () => {
        render(<ReleaseNotes release={release} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit notes' }));
        fireEvent.change(screen.getByLabelText('Notes for this version'), { target: { value: 'Draft' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(api.patch).not.toHaveBeenCalled();
        expect(screen.getByText('Templates per customer.')).toBeInTheDocument();

        api.patch.mockRejectedValue(Object.assign(new Error('Invalid'), { field: () => 'The notes field must not be greater than 20000 characters.' }));
        fireEvent.click(screen.getByRole('button', { name: 'Edit notes' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save notes' }));
        expect(await screen.findByText('The notes field must not be greater than 20000 characters.')).toBeInTheDocument();
        expect(screen.getByLabelText('Notes for this version')).toHaveAttribute('aria-invalid', 'true');
    });

    it('offers no editing on a yanked release', () => {
        render(<ReleaseNotes release={{ ...release, status: 'yanked' }} />);
        expect(screen.queryByRole('button', { name: /notes/i })).not.toBeInTheDocument();
    });
});

describe('dragging screenshots', () => {
    const extension = {
        slug: 'label-printer',
        name: 'Label Printer',
        screenshots: [
            { id: 's1', url: '/1.png', caption: 'Packing' },
            { id: 's2', url: '/2.png', caption: null },
            { id: 's3', url: '/3.png', caption: null },
        ],
    };
    const rect = { left: 0, top: 0, width: 200, height: 120, right: 200, bottom: 120, x: 0, y: 0 };
    // jsdom's drag events carry no coordinates; give them one.
    const dragAt = (type, element, clientX, dataTransfer) => {
        const event = createEvent[type](element, { dataTransfer });
        Object.defineProperty(event, 'clientX', { value: clientX });
        fireEvent(element, event);
    };

    it('drops a screenshot before another, sends the order once and announces it', async () => {
        api.patch.mockResolvedValue({});
        const { container } = render(<MediaSection extension={extension} faIcon={null} onFaIcon={() => {}} />);
        const tiles = () => [...container.querySelectorAll('[data-screenshot]')];
        tiles().forEach((tile) => (tile.getBoundingClientRect = () => rect));
        const dataTransfer = { setData: vi.fn(), effectAllowed: '', dropEffect: '' };

        fireEvent.dragStart(tiles()[2], { dataTransfer });
        expect(tiles()[2]).toHaveAttribute('data-dragging');
        dragAt('dragOver', tiles()[0], 20, dataTransfer);
        expect(tiles()[0]).toHaveAttribute('data-drop', 'before');
        dragAt('drop', tiles()[0], 20, dataTransfer);

        expect(api.patch).toHaveBeenCalledTimes(1);
        expect(api.patch).toHaveBeenCalledWith('/publisher/extensions/label-printer/screenshots', { order: ['s3', 's1', 's2'] });
        // The new order shows while it saves.
        expect(tiles().map((tile) => tile.dataset.screenshot)).toEqual(['s3', 's1', 's2']);
        await waitFor(() => expect(container.querySelector('[data-reorder-status]')).toHaveTextContent('Screenshot 3 moved to position 1 of 3, the cover.'));
    });

    it('does nothing when dropped where it started', () => {
        const { container } = render(<MediaSection extension={extension} faIcon={null} onFaIcon={() => {}} />);
        const tiles = [...container.querySelectorAll('[data-screenshot]')];
        tiles.forEach((tile) => (tile.getBoundingClientRect = () => rect));
        const dataTransfer = { setData: vi.fn() };

        fireEvent.dragStart(tiles[1], { dataTransfer });
        dragAt('dragOver', tiles[0], 150, dataTransfer);
        expect(tiles[0]).not.toHaveAttribute('data-drop');
        dragAt('drop', tiles[0], 150, dataTransfer);
        expect(api.patch).not.toHaveBeenCalled();
    });

    it('keeps the move buttons for keyboards and screen readers', async () => {
        api.patch.mockResolvedValue({});
        const { container } = render(<MediaSection extension={extension} faIcon={null} onFaIcon={() => {}} />);
        fireEvent.click(screen.getByRole('button', { name: 'Move screenshot 1 later' }));
        expect(api.patch).toHaveBeenCalledWith('/publisher/extensions/label-printer/screenshots', { order: ['s2', 's1', 's3'] });
        await waitFor(() => expect(container.querySelector('[data-reorder-status]')).toHaveTextContent('Screenshot “Packing” moved to position 2 of 3.'));
    });
});
