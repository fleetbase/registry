import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { renderToString } from 'react-dom/server';
import { resolvePage, pageTitle } from './lib/pages';

// Server-side rendering for first page loads (search engines, first paint). Run with
// `php artisan inertia:start-ssr` (or `node bootstrap/ssr/ssr.js`) after `npm run build`.
// Listens on 127.0.0.1 only; INERTIA_SSR_PORT moves it off 13714 (match INERTIA_SSR_URL).
createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            title: pageTitle,
            resolve: resolvePage,
            setup: ({ App, props }) => <App {...props} />,
        }),
    { host: '127.0.0.1', port: Number(process.env.INERTIA_SSR_PORT || 13714) }
);
