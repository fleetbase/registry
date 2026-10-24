import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import CodeInput from './CodeInput';

const boxes = () => screen.getAllByRole('textbox');

describe('CodeInput', () => {
    it('renders six labelled single-digit boxes', () => {
        render(<CodeInput />);
        expect(boxes()).toHaveLength(6);
        expect(screen.getByLabelText('Digit 1 of 6')).toHaveAttribute('inputmode', 'numeric');
        expect(screen.getByLabelText('Digit 6 of 6')).toBeInTheDocument();
    });

    it('moves to the next box while typing and completes on the sixth digit', async () => {
        const user = userEvent.setup();
        const onComplete = vi.fn();
        const onChange = vi.fn();
        render(<CodeInput onComplete={onComplete} onChange={onChange} />);

        await user.click(boxes()[0]);
        await user.keyboard('4');
        expect(boxes()[1]).toHaveFocus();
        await user.keyboard('1x9072');

        expect(boxes().map((box) => box.value)).toEqual(['4', '1', '9', '0', '7', '2']);
        expect(onComplete).toHaveBeenCalledTimes(1);
        expect(onComplete).toHaveBeenCalledWith('419072');
        expect(onChange).toHaveBeenLastCalledWith('419072');
    });

    it('fills every box from a pasted code and submits it, wherever it was pasted', () => {
        const onComplete = vi.fn();
        render(<CodeInput onComplete={onComplete} />);

        fireEvent.paste(boxes()[3], { clipboardData: { getData: () => ' 419-072 ' } });

        expect(boxes().map((box) => box.value)).toEqual(['4', '1', '9', '0', '7', '2']);
        expect(onComplete).toHaveBeenCalledWith('419072');
    });

    it('fills from the box pasted into when the paste is shorter than a code, without completing', () => {
        const onComplete = vi.fn();
        render(<CodeInput onComplete={onComplete} />);

        fireEvent.paste(boxes()[1], { clipboardData: { getData: () => '12' } });

        expect(boxes().map((box) => box.value)).toEqual(['', '1', '2', '', '', '']);
        expect(onComplete).not.toHaveBeenCalled();
    });

    it('accepts an autofilled one-time code typed into the first box', () => {
        const onComplete = vi.fn();
        render(<CodeInput onComplete={onComplete} />);

        fireEvent.change(boxes()[0], { target: { value: '555123' } });

        expect(onComplete).toHaveBeenCalledWith('555123');
    });

    it('goes back and clears the previous box on Backspace in an empty box', async () => {
        const user = userEvent.setup();
        render(<CodeInput />);

        await user.click(boxes()[0]);
        await user.keyboard('12');
        expect(boxes()[2]).toHaveFocus();
        await user.keyboard('{Backspace}');

        expect(boxes()[1]).toHaveFocus();
        expect(boxes().map((box) => box.value)).toEqual(['1', '', '', '', '', '']);
    });

    it('marks every box invalid for a wrong code', () => {
        render(<CodeInput invalid />);
        boxes().forEach((box) => expect(box).toHaveAttribute('aria-invalid', 'true'));
    });
});
