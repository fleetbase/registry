<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Find, install and publish extensions for Fleetbase.">
        <script>
            // Apply the stored theme before first paint; light unless the visitor chose dark.
            (function () {
                var theme;
                try { theme = window.localStorage.getItem('registry:theme'); } catch (e) {}
                document.documentElement.dataset.theme = theme === 'dark' ? 'dark' : 'light';
            })();
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;650;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
        <link rel="icon" href="/images/fleetbase-icon.svg" type="image/svg+xml">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx', "resources/js/pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
