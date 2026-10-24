import { expect, test, vi } from 'vitest';
import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Dialog from './Dialog';

function Form() {
    const [value, setValue] = useState('');
    return (
        <Dialog open title="Name it" onClose={() => {}}>
            <input aria-label="First" />
            <input aria-label="Second" value={value} onChange={(event) => setValue(event.target.value)} />
        </Dialog>
    );
}

test('keeps focus in the field being typed in when the parent re-renders', async () => {
    render(<Form />);
    const second = screen.getByLabelText('Second');
    await userEvent.click(second);
    await userEvent.type(second, 'abc');
    expect(second).toHaveValue('abc');
    expect(second).toHaveFocus();
});

test('Escape closes with the latest onClose and not when not dismissible', async () => {
    const onClose = vi.fn();
    const { rerender } = render(<Dialog open title="T" onClose={() => {}} dismissible={false}><button>ok</button></Dialog>);
    await userEvent.keyboard('{Escape}');
    rerender(<Dialog open title="T" onClose={onClose} dismissible><button>ok</button></Dialog>);
    await userEvent.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);
});
