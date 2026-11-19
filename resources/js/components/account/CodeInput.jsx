import { useRef, useState } from 'react';
import './account.css';

/**
 * A verification code as six single-digit boxes. Typing moves to the next box, Backspace on an
 * empty box goes back, and pasting (or an autofilled one-time code) fills every box from the
 * first; once all six are filled `onComplete(code)` runs, so a pasted code submits itself.
 */
export default function CodeInput({ length = 6, onChange, onComplete, invalid = false, disabled = false, describedBy, autoFocus = false }) {
    const [digits, setDigits] = useState(() => Array(length).fill(''));
    const refs = useRef([]);

    function focus(index) {
        const input = refs.current[Math.max(0, Math.min(length - 1, index))];
        input?.focus();
        input?.select?.();
    }

    function commit(next) {
        setDigits(next);
        const code = next.join('');
        onChange?.(code);
        if (next.every((digit) => digit !== '')) {
            onComplete?.(code);
        }
    }

    /** Spread several digits across the boxes from `start` (a paste, or an autofilled code). */
    function fill(start, text) {
        const incoming = String(text).replace(/\D/g, '');
        if (!incoming) {
            return false;
        }
        // A whole code always starts at the first box, wherever it was pasted.
        const from = incoming.length >= length ? 0 : start;
        const next = [...digits];
        incoming
            .slice(0, length - from)
            .split('')
            .forEach((digit, offset) => {
                next[from + offset] = digit;
            });
        commit(next);
        focus(Math.min(length - 1, from + incoming.length));
        return true;
    }

    function onInput(index, event) {
        const value = event.target.value.replace(/\D/g, '');
        if (value.length > 1) {
            fill(index, value);
            return;
        }
        const next = [...digits];
        next[index] = value;
        commit(next);
        if (value && index < length - 1) {
            focus(index + 1);
        }
    }

    function onKeyDown(index, event) {
        if (event.key === 'Backspace' && !digits[index] && index > 0) {
            event.preventDefault();
            const next = [...digits];
            next[index - 1] = '';
            commit(next);
            focus(index - 1);
        } else if (event.key === 'ArrowLeft' && index > 0) {
            event.preventDefault();
            focus(index - 1);
        } else if (event.key === 'ArrowRight' && index < length - 1) {
            event.preventDefault();
            focus(index + 1);
        }
    }

    function onPaste(index, event) {
        if (fill(index, event.clipboardData?.getData('text') ?? '')) {
            event.preventDefault();
        }
    }

    return (
        <div className="otp">
            {digits.map((digit, index) => (
                <input
                    key={index}
                    ref={(node) => {
                        refs.current[index] = node;
                    }}
                    value={digit}
                    onChange={(event) => onInput(index, event)}
                    onKeyDown={(event) => onKeyDown(index, event)}
                    onPaste={(event) => onPaste(index, event)}
                    onFocus={(event) => event.target.select()}
                    inputMode="numeric"
                    pattern="[0-9]*"
                    autoComplete={index === 0 ? 'one-time-code' : 'off'}
                    aria-label={`Digit ${index + 1} of ${length}`}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                    disabled={disabled}
                    autoFocus={autoFocus && index === 0}
                    data-digit={index}
                />
            ))}
        </div>
    );
}
