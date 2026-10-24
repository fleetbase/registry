import { useCallback, useEffect, useState } from 'react';

const KEY = 'registry:theme';

function current() {
    if (typeof document === 'undefined') {
        return 'light';
    }
    return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
}

/**
 * Light and dark themes: `data-theme` on <html>, remembered per browser; without a choice the
 * OS preference applies (set before first paint in app.blade.php).
 */
export function useTheme() {
    const [theme, setThemeState] = useState('light');

    useEffect(() => setThemeState(current()), []);

    const setTheme = useCallback((next) => {
        const value = next === 'dark' ? 'dark' : 'light';
        const root = document.documentElement;
        root.classList.add('theme-fade');
        root.dataset.theme = value;
        window.setTimeout(() => root.classList.remove('theme-fade'), 250);
        try {
            window.localStorage.setItem(KEY, value);
        } catch {
            // Storage can be unavailable (private mode); the theme still applies for this visit.
        }
        setThemeState(value);
    }, []);

    const toggle = useCallback(() => setTheme(current() === 'dark' ? 'light' : 'dark'), [setTheme]);

    return { theme, isDark: theme === 'dark', setTheme, toggle };
}
