import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Icon from './Icon';

const ToastContext = createContext({ show: () => {} });

/** Toasts: short confirmations after an action, announced politely. */
export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const id = useRef(0);
    const { flash } = usePage().props;

    const dismiss = useCallback((key) => setToasts((list) => list.filter((toast) => toast.key !== key)), []);

    const show = useCallback(
        (message, { type = 'ok', action } = {}) => {
            const key = ++id.current;
            setToasts((list) => [...list.slice(-2), { key, message, type, action }]);
            setTimeout(() => dismiss(key), type === 'error' ? 8000 : 4500);
        },
        [dismiss]
    );

    useEffect(() => {
        if (flash?.message) {
            show(flash.message, { type: flash.type ?? 'ok' });
        }
    }, [flash, show]);

    return (
        <ToastContext.Provider value={{ show }}>
            {children}
            <div role="status" aria-live="polite" style={{ position: 'fixed', bottom: 20, right: 20, left: 20, zIndex: 60, display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 10, pointerEvents: 'none' }}>
                {toasts.map((toast) => (
                    <div key={toast.key} className="toast" style={{ pointerEvents: 'auto', maxWidth: 440 }} data-toast={toast.type}>
                        <Icon name={toast.type === 'error' ? 'alert' : 'check'} />
                        <span className="grow">{toast.message}</span>
                        {toast.action}
                        <button type="button" className="icon-btn" style={{ width: 32, height: 32, color: 'inherit' }} aria-label="Dismiss" onClick={() => dismiss(toast.key)}>
                            <Icon name="close" size={16} />
                        </button>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext).show;
}
