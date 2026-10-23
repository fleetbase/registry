import { useEffect, useId, useState } from 'react';
import DOMPurify from 'dompurify';
import Icon from './Icon';

/** Empty, no-results and error states: what happened, and the one next step. */
export function EmptyState({ icon = 'box', tone, title, children, actions }) {
    const glyph = tone === 'fail' ? { background: 'var(--fail-bg)', color: 'var(--fail)' } : tone === 'you' ? { background: 'var(--you-bg)', color: 'var(--you)' } : undefined;
    return (
        <div className="empty" data-empty-state>
            <span className="empty-glyph" style={glyph} aria-hidden="true">
                <Icon name={icon} size={26} />
            </span>
            <h2 className="h3">{title}</h2>
            {children && <div className="ink2" style={{ maxWidth: 460 }}>{children}</div>}
            {actions && <div className="row wrapflex" style={{ justifyContent: 'center' }}>{actions}</div>}
        </div>
    );
}

/** A callout: info (blue), you (amber), fail (red), ok (green). */
export function Note({ tone = 'info', icon, children, className = '', ...rest }) {
    const icons = { info: 'info', you: 'warning', fail: 'alert', ok: 'check' };
    return (
        <div className={`note note-${tone} ${className}`} {...rest}>
            <Icon name={icon ?? icons[tone]} />
            <div className="grow">{children}</div>
        </div>
    );
}

/**
 * A labelled form field. The input gets the generated id through the render prop, so the label
 * always names it: `<Field label="Name">{(id) => <input id={id} className="input" />}</Field>`.
 */
export function Field({ label, optional, help, error, children, className = '' }) {
    const id = useId();
    const helpId = `${id}-help`;
    return (
        <div className={`field ${className}`}>
            <label className="label" htmlFor={id}>
                {label}
                {optional && <span className="opt">{optional === true ? 'optional' : optional}</span>}
            </label>
            {children(id, { 'aria-describedby': help || error ? helpId : undefined, 'aria-invalid': error ? true : undefined })}
            {error ? (
                <span id={helpId} className="err">
                    <Icon name="alert" size={14} strokeWidth={2.4} />
                    {error}
                </span>
            ) : (
                help && (
                    <span id={helpId} className="help">
                        {help}
                    </span>
                )
            )}
        </div>
    );
}

/** A segmented control: tabs (`role="tab"`) or radios (`role="radio"`). */
export function Segmented({ options, value, onChange, label, role = 'tab', className = '' }) {
    return (
        <div className={`seg ${className}`} role={role === 'tab' ? 'tablist' : 'radiogroup'} aria-label={label}>
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role={role}
                    aria-selected={role === 'tab' ? option.value === value : undefined}
                    aria-checked={role === 'radio' ? option.value === value : undefined}
                    data-selected={option.value === value}
                    onClick={() => onChange(option.value)}
                    style={option.value === value ? { background: 'var(--surface)', color: 'var(--ink)', boxShadow: 'var(--e1), 0 0 0 1px var(--line)' } : undefined}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export function Skeleton({ width = '100%', height = 12, radius = 8, className = '' }) {
    return <div className={`skel ${className}`} style={{ width, height, borderRadius: radius }} aria-hidden="true" />;
}

/**
 * Publisher-written rich text (overview, release notes) as HTML after DOMPurify strips scripts,
 * handlers, iframes, images and styles. DOMPurify needs a DOM, so server rendering and the first
 * client render show the text without markup; the sanitised HTML replaces it after mount.
 */
export function SafeHtml({ html, className = '' }) {
    const [mounted, setMounted] = useState(false);
    useEffect(() => setMounted(true), []);

    const text = String(html ?? '');
    const isHtml = /<[a-z][\s\S]*>/i.test(text);
    let markup;
    if (isHtml && mounted && DOMPurify.isSupported) {
        markup = DOMPurify.sanitize(text, { FORBID_TAGS: ['style', 'iframe', 'form', 'input', 'img'], FORBID_ATTR: ['style'] });
    } else {
        const plain = isHtml ? text.replace(/<\/(p|li|h[1-6]|div)>/gi, '\n\n').replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]*>/g, '') : text;
        const escaped = plain.replace(/&(?!(amp|lt|gt|quot|#39);)/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        markup = escaped
            .split(/\n{2,}/)
            .map((paragraph) => paragraph.trim())
            .filter(Boolean)
            .map((paragraph) => `<p>${paragraph.replace(/\n/g, '<br>')}</p>`)
            .join('');
    }
    return <div className={`prose ${className}`} dangerouslySetInnerHTML={{ __html: markup }} />;
}
