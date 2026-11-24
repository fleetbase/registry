import { useEffect, useRef, useState } from 'react';
import Icon from '../Icon';
import { moveItem } from './reorder';

export const MAX_LINES = 5;

export const MAX_LINE_LENGTH = 120;

/** The lines to save: trimmed, blanks dropped. */
export function cleanLines(lines) {
    return (lines ?? []).map((line) => String(line ?? '').trim()).filter(Boolean);
}

/**
 * A short list of lines (highlights, setup steps): type in each line, add one, remove one, move
 * one up or down. Add (or Enter in a line) opens a new line and moves the cursor there. `numbered` shows step numbers. Read-only when `disabled` (set in extension.json).
 */
export default function LinesEditor({ id, value = [], onChange, describedBy, disabled = false, numbered = false, itemLabel = 'Line', addLabel = 'Add a line', placeholder = '' }) {
    const lines = value ?? [];
    const full = lines.length >= MAX_LINES;
    const list = useRef(null);
    const [focus, setFocus] = useState(null);
    const update = (index, text) => onChange(lines.map((line, i) => (i === index ? text : line)));
    const remove = (index) => onChange(lines.filter((_, i) => i !== index));
    const move = (index, offset) => onChange(moveItem(lines, index, index + offset));
    const add = (at = lines.length) => {
        if (full) {
            return;
        }
        onChange([...lines.slice(0, at), '', ...lines.slice(at)]);
        setFocus(at);
    };

    useEffect(() => {
        if (focus === null) {
            return;
        }
        list.current?.querySelectorAll('input')[focus]?.focus();
        setFocus(null);
    }, [focus, lines.length]);

    return (
        <div className="col" style={{ gap: 8 }} data-lines ref={list}>
            {lines.length > 0 && (
                <ol className="col" style={{ gap: 8, listStyle: 'none', margin: 0, padding: 0 }}>
                    {lines.map((line, index) => (
                        <li key={index} className="row" style={{ gap: 6 }} data-line={index}>
                            <span className="small muted mono" aria-hidden="true" style={{ width: 18, textAlign: 'right', flex: 'none' }}>
                                {numbered ? `${index + 1}.` : '•'}
                            </span>
                            <input
                                id={index === 0 ? id : undefined}
                                className="input grow"
                                value={line}
                                onChange={(event) => update(index, event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        add(index + 1);
                                    }
                                }}
                                maxLength={MAX_LINE_LENGTH}
                                placeholder={placeholder}
                                disabled={disabled || undefined}
                                aria-label={`${itemLabel} ${index + 1}`}
                                aria-describedby={describedBy}
                                autoComplete="off"
                            />
                            {!disabled && (
                                <>
                                    <button
                                        type="button"
                                        className="icon-btn"
                                        aria-label={`Move ${itemLabel.toLowerCase()} ${index + 1} up`}
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                    >
                                        <Icon name="arrowUp" size={16} />
                                    </button>
                                    <button
                                        type="button"
                                        className="icon-btn"
                                        aria-label={`Move ${itemLabel.toLowerCase()} ${index + 1} down`}
                                        disabled={index === lines.length - 1}
                                        onClick={() => move(index, 1)}
                                    >
                                        <Icon name="arrowDown" size={16} />
                                    </button>
                                    <button type="button" className="icon-btn" aria-label={`Remove ${itemLabel.toLowerCase()} ${index + 1}`} onClick={() => remove(index)}>
                                        <Icon name="trash" size={16} />
                                    </button>
                                </>
                            )}
                        </li>
                    ))}
                </ol>
            )}
            {lines.length === 0 && disabled && <span className="small muted">None.</span>}
            {!disabled && (
                <button type="button" className="btn btn-sm self-start" onClick={() => add()} disabled={full} aria-describedby={lines.length === 0 ? describedBy : undefined}>
                    <Icon name="plus" size={14} /> {full ? `At most ${MAX_LINES}` : addLabel}
                </button>
            )}
        </div>
    );
}
