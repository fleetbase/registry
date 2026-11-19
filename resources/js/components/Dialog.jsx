import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import Icon from './Icon';

/**
 * A modal dialog: focus moves in on open and returns on close, Escape and the close button
 * close it (a click outside does not, so nothing typed is lost), Tab stays inside.
 */
export default function Dialog({ open, title, onClose, children, footer, size = 520, labelledBy = 'dialog-title', dismissible = true }) {
    const ref = useRef(null);
    const previous = useRef(null);
    // The latest onClose and dismissible, read by the key handler: the effect below runs only when
    // the dialog opens or closes, so focus never jumps back to the first field while typing.
    const latest = useRef({ onClose, dismissible });
    useEffect(() => {
        latest.current = { onClose, dismissible };
    });

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        previous.current = document.activeElement;
        const node = ref.current;
        const focusable = () => [...node.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter((el) => !el.disabled && el.getAttribute('aria-hidden') !== 'true');
        (node.querySelector('[data-autofocus]') ?? focusable()[0] ?? node).focus();

        function onKey(event) {
            if (event.key === 'Escape' && latest.current.dismissible) {
                event.stopPropagation();
                latest.current.onClose?.();
            }
            if (event.key === 'Tab') {
                const items = focusable();
                if (!items.length) {
                    return;
                }
                const first = items[0];
                const last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        }
        node.addEventListener('keydown', onKey);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            node.removeEventListener('keydown', onKey);
            document.body.style.overflow = overflow;
            previous.current?.focus?.();
        };
    }, [open]);

    if (!open) {
        return null;
    }

    return createPortal(
        <div className="scrim" style={{ position: 'fixed', inset: 0, zIndex: 50, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}>
            <section ref={ref} className="dialog" role="dialog" aria-modal="true" aria-labelledby={labelledBy} tabIndex={-1} style={{ maxWidth: size, maxHeight: 'calc(100vh - 32px)', overflowY: 'auto' }}>
                <div className="dialog-head">
                    <h2 id={labelledBy} className="h3">
                        {title}
                    </h2>
                    {dismissible && (
                        <button type="button" className="icon-btn" aria-label="Close" onClick={onClose}>
                            <Icon name="close" />
                        </button>
                    )}
                </div>
                <div className="dialog-body">{children}</div>
                {footer && <div className="dialog-foot">{footer}</div>}
            </section>
        </div>,
        document.body
    );
}
