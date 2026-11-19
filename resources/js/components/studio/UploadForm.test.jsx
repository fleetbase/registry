import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const { upload, visit } = vi.hoisted(() => ({ upload: vi.fn(), visit: vi.fn() }));

// Whole pages render in jsdom; give them room when the machine is busy.
vi.setConfig({ testTimeout: 30000 });

vi.mock('../../lib/api', () => ({ api: { upload } }));
vi.mock('@inertiajs/react', () => ({ router: { visit } }));

import UploadForm, { slotFor, uploadReady } from './UploadForm';

const file = (name, size = 10) => new File(['x'.repeat(size)], name);
const extension = { slug: 'label-printer', name: 'Label Printer' };

describe('upload form', () => {
    beforeEach(() => {
        upload.mockReset();
        visit.mockReset();
    });

    it('sorts files into slots by name', () => {
        expect(slotFor('extension.json')).toBe('manifest');
        expect(slotFor('acme-label-printer-engine-0.4.0.tgz')).toBe('engine');
        expect(slotFor('acme-label-printer-api-0.4.0.tar')).toBe('api');
        expect(slotFor('api.tar.gz')).toBe('api');
        expect(slotFor('engine-dist.tar.gz')).toBe('dist');
        expect(slotFor('notes.txt')).toBeNull();
    });

    it('needs a manifest and the engine or the API', () => {
        expect(uploadReady({})).toBe(false);
        expect(uploadReady({ manifest: 1 })).toBe(false);
        expect(uploadReady({ engine: 1, api: 1 })).toBe(false);
        expect(uploadReady({ manifest: 1, engine: 1 })).toBe(true);
        expect(uploadReady({ manifest: 1, api: 1 })).toBe(true);
    });

    it('enables the upload once the files are there, then uploads and opens the release', async () => {
        upload.mockResolvedValue({ id: 'rel_123', version: '0.4.0', status: 'checking' });
        const { container } = render(<UploadForm extension={extension} />);
        const button = screen.getByRole('button', { name: /upload and run checks/i });
        expect(button).toBeDisabled();

        fireEvent.change(container.querySelector('#upload-manifest'), { target: { files: [file('extension.json')] } });
        expect(button).toBeDisabled();

        fireEvent.change(container.querySelector('#upload-api'), { target: { files: [file('api.tar')] } });
        expect(button).toBeEnabled();

        fireEvent.click(screen.getByLabelText(/submit for review when the checks pass/i));
        fireEvent.change(screen.getByLabelText(/channel/i), { target: { value: 'beta' } });
        fireEvent.click(button);

        await waitFor(() => expect(visit).toHaveBeenCalledWith('/studio/label-printer/releases/rel_123'));
        const [url, fields] = upload.mock.calls[0];
        expect(url).toBe('/publisher/extensions/label-printer/releases');
        expect(fields.manifest.name).toBe('extension.json');
        expect(fields.api.name).toBe('api.tar');
        expect(fields.engine).toBeUndefined();
        expect(fields).toMatchObject({ channel: 'beta', submit: true });
    });

    it('fills the slots from a drop and disables again when a required file is removed', () => {
        const { container } = render(<UploadForm extension={extension} />);
        const button = screen.getByRole('button', { name: /upload and run checks/i });
        fireEvent.drop(container.querySelector('.sd-drop'), { dataTransfer: { files: [file('extension.json'), file('engine.tgz'), file('readme.md')] } });

        expect(container.querySelector('[data-slot="manifest"]').dataset.filled).toBe('true');
        expect(container.querySelector('[data-slot="engine"]').dataset.filled).toBe('true');
        expect(screen.getByText(/readme\.md/)).toBeInTheDocument();
        expect(button).toBeEnabled();

        fireEvent.click(screen.getByRole('button', { name: /remove manifest/i }));
        expect(button).toBeDisabled();
    });

    it('shows field errors from the service next to the file', async () => {
        upload.mockRejectedValue(Object.assign(new Error('The manifest needs a SemVer `version`.'), { fields: { manifest: ['The manifest needs a SemVer `version`.'] }, field: (name) => (name === 'manifest' ? 'The manifest needs a SemVer `version`.' : null) }));
        const { container } = render(<UploadForm extension={extension} />);
        fireEvent.change(container.querySelector('#upload-manifest'), { target: { files: [file('extension.json')] } });
        fireEvent.change(container.querySelector('#upload-engine'), { target: { files: [file('engine.tgz')] } });
        fireEvent.click(screen.getByRole('button', { name: /upload and run checks/i }));

        expect(await screen.findByText('The manifest needs a SemVer `version`.')).toBeInTheDocument();
        expect(visit).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: /upload and run checks/i })).toBeEnabled();
    });
});
