import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    // The SSR bundle carries its dependencies, so the runtime image runs it with Node alone
    // (no node_modules).
    ssr: {
        noExternal: true,
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/test/setup.js'],
        include: ['resources/js/**/*.test.{js,jsx}'],
        // Page tests type into jsdom forms; a busy CI runner can take longer than the 5 s default.
        testTimeout: 20_000,
    },
});
