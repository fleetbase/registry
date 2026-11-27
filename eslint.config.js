import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';

export default [
    { ignores: ['public/**', 'vendor/**', 'bootstrap/ssr/**', 'node_modules/**', 'legacy/**'] },
    js.configs.recommended,
    {
        files: ['resources/js/**/*.{js,jsx}'],
        plugins: { 'react-hooks': reactHooks },
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: { ecmaFeatures: { jsx: true } },
            globals: { ...globals.browser, ...globals.node },
        },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        files: ['resources/js/**/*.test.{js,jsx}', 'resources/js/test/**'],
        languageOptions: { globals: { ...globals.browser, ...globals.node, vi: 'readonly', describe: 'readonly', it: 'readonly', expect: 'readonly', beforeEach: 'readonly', afterEach: 'readonly' } },
    },
];
