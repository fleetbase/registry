<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registry Service settings
    |--------------------------------------------------------------------------
    */

    'version' => env('REGISTRY_VERSION', '2.0.0-dev'),

    // Absolute base URL written into package metadata (npm dist.tarball, Composer dist.url).
    // Empty means the request's own scheme and host.
    'public_url' => env('REGISTRY_PUBLIC_URL'),

    // Cache lifetime for npm packuments and Composer metadata of free packages, in seconds.
    'package_metadata_cache_seconds' => (int) env('REGISTRY_PACKAGE_METADATA_CACHE_SECONDS', 60),

    // Cache lifetime for public catalog responses, in seconds.
    'catalog_cache_seconds' => (int) env('REGISTRY_CATALOG_CACHE_SECONDS', 60),

    // Package namespaces (npm scope / Composer vendor) only an organization flagged
    // `is_staff` may own. Grant the flag with `php artisan registry:staff <org>`.
    'reserved_scopes' => ['fleetbase'],

    // Maximum size of an uploaded engine or api archive in bytes (upload limit and `size` check).
    'max_bundle_bytes' => (int) env('REGISTRY_MAX_BUNDLE_BYTES', 50 * 1024 * 1024),

    'releases' => [
        // Maximum size of the optional prebuilt `dist` archive in bytes.
        'max_dist_bytes' => (int) env('REGISTRY_MAX_DIST_BYTES', 100 * 1024 * 1024),

        // Limits applied to each archive's contents by the `size` check.
        'max_unpacked_bytes' => (int) env('REGISTRY_MAX_UNPACKED_BYTES', 300 * 1024 * 1024),
        'max_file_bytes' => (int) env('REGISTRY_MAX_FILE_BYTES', 25 * 1024 * 1024),
        'max_entries' => (int) env('REGISTRY_MAX_ARCHIVE_ENTRIES', 20000),

        // File extensions the `archive` check refuses in any archive. Native executables are
        // also refused by their magic bytes whatever they are called.
        'forbidden_extensions' => [
            'exe', 'dll', 'so', 'dylib', 'bin', 'msi', 'com', 'scr', 'bat', 'cmd', 'ps1', 'vbs',
            'jar', 'class', 'phar', 'apk', 'dmg', 'deb', 'rpm', 'pkg', 'app', 'iso', 'img',
        ],

        // Secrets scanner: `auto` runs the built-in ruleset and, when the gitleaks binary is
        // found, gitleaks as well; `builtin` runs only the ruleset; `off` skips the scan and
        // records a warning on the check.
        'secrets_scanner' => env('REGISTRY_SECRETS_SCANNER', 'auto'),
        'gitleaks_binary' => env('REGISTRY_GITLEAKS_BINARY', 'gitleaks'),
        'gitleaks_timeout' => (int) env('REGISTRY_GITLEAKS_TIMEOUT', 120),

        // Queue for `RunReleaseChecks`. Null means the default queue.
        'checks_queue' => env('REGISTRY_CHECKS_QUEUE'),
    ],

    'import' => [
        // Disk holding the cloud `files` objects (bundles, icons) that `registry:import` copies.
        'source_disk' => env('REGISTRY_IMPORT_SOURCE_DISK', 'import_source'),
    ],

    'storage' => [
        // Disk that holds tarballs, prebuilt dist archives, icons and screenshots.
        'disk' => env('REGISTRY_STORAGE_DISK', 's3'),
        'prefixes' => [
            'tarballs' => 'tarballs',
            'dist' => 'dist',
            'icons' => 'icons',
            'screenshots' => 'screenshots',
        ],
    ],

];
