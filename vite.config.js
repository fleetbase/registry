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
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/test/setup.js'],
        include: ['resources/js/**/*.test.{js,jsx}'],
    },
});
