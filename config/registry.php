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

    // Maximum uploaded bundle size in bytes (used by release checks in Workstream C).
    'max_bundle_bytes' => (int) env('REGISTRY_MAX_BUNDLE_BYTES', 50 * 1024 * 1024),

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
