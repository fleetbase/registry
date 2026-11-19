import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { resolvePage, pageTitle } from './lib/pages';

createInertiaApp({
    title: pageTitle,
    resolve: resolvePage,
    progress: { color: '#3485e2', delay: 150 },
    setup({ el, App, props }) {
        if (el.hasChildNodes()) {
            hydrateRoot(el, <App {...props} />);
            return;
        }
        createRoot(el).render(<App {...props} />);
    },
});
