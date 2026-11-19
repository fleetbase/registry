import { useEffect, useRef } from 'react';
import DOMPurify from 'dompurify';

const ALLOWED = { ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'h2', 'h3', 'ul', 'ol', 'li', 'a', 'code', 'div'], ALLOWED_ATTR: ['href'] };

/** The editor's HTML, or '' when it holds no text (so an emptied editor reads as missing). */
export function overviewHtml(node) {
    const text = (node.textContent ?? '').trim();
    if (!text) {
        return '';
    }
    return DOMPurify.isSupported ? DOMPurify.sanitize(node.innerHTML, ALLOWED) : node.innerHTML;
}

/**
 * The overview: a contenteditable with a small toolbar (document.execCommand). Stores HTML in
 * `promotional_text`. Remount (change `key`) to load a different value.
 */
export default function OverviewEditor({ initial = '', onChange, labelledBy }) {
    const ref = useRef(null);

    useEffect(() => {
        if (ref.current) {
            const html = String(initial ?? '');
            const isHtml = /<[a-z][\s\S]*>/i.test(html);
            ref.current.innerHTML = isHtml ? (DOMPurify.isSupported ? DOMPurify.sanitize(html, ALLOWED) : '') : '';
            if (!isHtml && html) {
                ref.current.textContent = html;
            }
        }
        // Only the first value: later changes come from the editor itself.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function exec(command, value = null) {
        ref.current?.focus();
        document.execCommand(command, false, value);
        emit();
    }

    function emit() {
        if (ref.current) {
            onChange(overviewHtml(ref.current));
        }
    }

    function link() {
        const url = window.prompt('Link address (https://…)');
        if (url && /^https?:\/\//i.test(url.trim())) {
            exec('createLink', url.trim());
        }
    }

    function code() {
        const selection = window.getSelection?.()?.toString() ?? '';
        if (selection) {
            const escaped = selection.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            exec('insertHTML', `<code>${escaped}</code>`);
        }
    }

    const tools = [
        { label: 'Bold', text: <strong>B</strong>, run: () => exec('bold') },
        { label: 'Italic', text: <em>I</em>, run: () => exec('italic') },
        { label: 'Heading', text: 'H2', run: () => exec('formatBlock', 'h2') },
        { label: 'Paragraph', text: '¶', run: () => exec('formatBlock', 'p') },
        { label: 'Bulleted list', text: '• List', run: () => exec('insertUnorderedList') },
        { label: 'Numbered list', text: '1. List', run: () => exec('insertOrderedList') },
        { label: 'Link', text: 'Link', run: link },
        { label: 'Code', text: 'Code', run: code },
    ];

    return (
        <div>
            <div className="sd-toolbar" role="toolbar" aria-label="Formatting">
                {tools.map((tool) => (
                    <button key={tool.label} type="button" aria-label={tool.label} title={tool.label} onMouseDown={(e) => e.preventDefault()} onClick={tool.run}>
                        {tool.text}
                    </button>
                ))}
            </div>
            <div
                ref={ref}
                className="card-body ink2 prose sd-overview"
                contentEditable
                suppressContentEditableWarning
                role="textbox"
                aria-multiline="true"
                aria-labelledby={labelledBy}
                data-placeholder="What it does, who it is for, what it needs. Shown on your extension page."
                onInput={emit}
                onBlur={emit}
                data-overview
            />
        </div>
    );
}
