<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registry Service settings
    |--------------------------------------------------------------------------
    */

    'version' => env('REGISTRY_VERSION', '2.0.0-dev'),

    // Cache lifetime for public catalog responses, in seconds.
    'catalog_cache_seconds' => (int) env('REGISTRY_CATALOG_CACHE_SECONDS', 60),

    // Package namespaces reserved for the Fleetbase staff organization.
    'reserved_scopes' => ['fleetbase'],

    // Slug of the staff organization that may publish reserved scopes and review releases.
    'staff_organization' => env('REGISTRY_STAFF_ORGANIZATION', 'fleetbase'),

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
