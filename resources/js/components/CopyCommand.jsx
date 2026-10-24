import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';

/** A command (or any value) with a copy button; the label reads "Copied" for 1.5 s. */
export default function CopyCommand({ value, prompt = '$', label = 'command', className = '' }) {
    const [copied, setCopied] = useState(false);
    const timer = useRef();

    useEffect(() => () => clearTimeout(timer.current), []);

    async function copy() {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            clearTimeout(timer.current);
            timer.current = setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard access can be refused; the value stays selectable.
        }
    }

    return (
        <div className={`cmd ${className}`}>
            {prompt && (
                <span className="p" aria-hidden="true">
                    {prompt}
                </span>
            )}
            <code data-copy-value>{value}</code>
            <button type="button" className={`copy ${copied ? 'done' : ''}`} onClick={copy} aria-label={copied ? `Copied ${label}` : `Copy ${label}`}>
                <Icon name={copied ? 'check' : 'copy'} size={14} strokeWidth={copied ? 2.6 : 2} />
                <span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span>
            </button>
        </div>
    );
}
