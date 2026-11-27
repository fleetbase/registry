import { useEffect, useRef, useState } from 'react';
import { api } from '../../lib/api';
import { Skeleton } from '../Ui';

/** Connect.js appearance from the site's theme tokens, read when the component mounts. */
function appearance() {
    const style = getComputedStyle(document.documentElement);
    const token = (name, fallback) => style.getPropertyValue(name).trim() || fallback;
    return {
        overlays: 'dialog',
        variables: {
            fontFamily: token('--font', 'system-ui'),
            colorPrimary: token('--accent', '#1c6cc7'),
            colorText: token('--ink', '#111'),
            colorBackground: token('--surface', '#fff'),
            colorBorder: token('--line', '#ddd'),
            borderRadius: '10px',
        },
    };
}

/**
 * One Stripe Connect embedded component (`account-onboarding`, `account-management`, `payouts`,
 * `notification-banner`, …) for an organization's connected account. Connect.js loads in the browser
 * only; each mount asks the service for an Account Session (`POST …/stripe/account-session`).
 */
export default function ConnectEmbed({ organization, publishableKey, component, onExit, label }) {
    const host = useRef(null);
    const exit = useRef(onExit);
    const [state, setState] = useState('loading');
    exit.current = onExit;

    useEffect(() => {
        let cancelled = false;
        let element = null;

        (async () => {
            try {
                const { loadConnectAndInitialize } = await import('@stripe/connect-js');
                if (cancelled) {
                    return;
                }
                const instance = loadConnectAndInitialize({
                    publishableKey,
                    fetchClientSecret: async () => (await api.post(`organizations/${organization}/stripe/account-session`)).client_secret,
                    appearance: appearance(),
                });
                element = instance.create(component);
                if (component === 'account-onboarding') {
                    element.setOnExit?.(() => exit.current?.());
                }
                host.current?.replaceChildren(element);
                setState('ready');
            } catch {
                if (!cancelled) {
                    setState('failed');
                }
            }
        })();

        return () => {
            cancelled = true;
            element?.remove();
        };
    }, [organization, publishableKey, component]);

    return (
        <div aria-label={label} aria-busy={state === 'loading' || undefined} data-connect={component}>
            {state === 'loading' && <Skeleton height={120} radius={12} />}
            {state === 'failed' && <p className="small muted">Stripe could not be loaded. Check your connection and reload the page.</p>}
            <div ref={host} />
        </div>
    );
}
