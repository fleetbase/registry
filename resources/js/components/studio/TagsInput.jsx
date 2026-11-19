import { useState } from 'react';
import Icon from '../Icon';

export const MAX_TAGS = 20;

/** Add a tag (trimmed, at most 64 characters, no duplicates, at most 20). */
export function addTag(tags, value) {
    const tag = String(value ?? '').trim().replace(/,+$/, '').trim().slice(0, 64);
    if (!tag || tags.length >= MAX_TAGS || tags.some((existing) => existing.toLowerCase() === tag.toLowerCase())) {
        return tags;
    }
    return [...tags, tag];
}

/** Tags: Enter or a comma adds, Backspace in the empty input removes the last one. */
export default function TagsInput({ id, value = [], onChange, describedBy }) {
    const [text, setText] = useState('');
    const full = value.length >= MAX_TAGS;

    function commit(raw = text) {
        const parts = String(raw).split(',');
        let next = value;
        for (const part of parts) {
            next = addTag(next, part);
        }
        if (next !== value) {
            onChange(next);
        }
        setText('');
    }

    function onKeyDown(event) {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            commit();
        } else if (event.key === 'Backspace' && text === '' && value.length) {
            event.preventDefault();
            onChange(value.slice(0, -1));
        }
    }

    function onInput(event) {
        const next = event.target.value;
        if (next.includes(',')) {
            commit(next);
        } else {
            setText(next);
        }
    }

    return (
        <div className="input sd-tags" data-tags>
            <ul className="row wrapflex" style={{ gap: 6, listStyle: 'none', margin: 0, padding: 0 }} aria-label="Current tags">
                {value.map((tag) => (
                    <li key={tag} className="sd-tag">
                        {tag}
                        <button type="button" aria-label={`Remove tag ${tag}`} onClick={() => onChange(value.filter((t) => t !== tag))}>
                            <Icon name="close" size={14} />
                        </button>
                    </li>
                ))}
            </ul>
            <input
                id={id}
                value={text}
                onChange={onInput}
                onKeyDown={onKeyDown}
                onBlur={() => text.trim() && commit()}
                placeholder={full ? 'At most 20 tags' : 'Add a tag'}
                disabled={full}
                aria-describedby={describedBy}
                autoComplete="off"
                maxLength={64}
            />
        </div>
    );
}
