import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { renderToString } from 'react-dom/server';
import { resolvePage, pageTitle } from './lib/pages';

// Server-side rendering for public pages (search engines, first paint). Run with
// `php artisan inertia:start-ssr` after `npm run build`.
createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: pageTitle,
        resolve: resolvePage,
        setup: ({ App, props }) => <App {...props} />,
    })
);
