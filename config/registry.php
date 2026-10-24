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

    // Cache lifetime for publisher analytics reports (Studio Insights), in seconds.
    'analytics_cache_seconds' => (int) env('REGISTRY_ANALYTICS_CACHE_SECONDS', 60),

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

    'review' => [
        // Review requirements staff mark on approve and reject (`items: [{code, passed, note}]`);
        // `items.*.code` must be one of these codes. Served by GET /v1/admin/review-items.
        'items' => [
            ['code' => 'R1', 'label' => 'Manifest is valid'],
            ['code' => 'R2', 'label' => 'Installs on a clean instance'],
            ['code' => 'R3', 'label' => 'Listing is complete'],
            ['code' => 'R4', 'label' => 'No secrets or credentials'],
            ['code' => 'R5', 'label' => 'Permissions go through the auth schema'],
            ['code' => 'R6', 'label' => 'Pricing matches Stripe Connect'],
        ],
    ],

    'import' => [
        // Disk holding the cloud `files` objects (bundles, icons) that `registry:import` copies.
        'source_disk' => env('REGISTRY_IMPORT_SOURCE_DISK', 'import_source'),
    ],

    'cloud' => [
        // Shared with the cloud console's API (REGISTRY_CLOUD_PLATFORM_SECRET there too). It signs
        // `POST /v1/instances/register/cloud`; empty turns cloud registration off.
        'platform_secret' => env('REGISTRY_CLOUD_PLATFORM_SECRET'),

        // Organization that owns the cloud instance (slug); created on first use. Licences stay
        // with each cloud company's organization: the instance only acts for them.
        'organization' => env('REGISTRY_CLOUD_ORGANIZATION', 'fleetbase'),

        // Host the cloud API registers with (its APP_URL), e.g. `api.fleetbase.io`. The importer
        // attaches cloud installs to the instance of this host so the first registration
        // rotates onto it.
        'host' => env('REGISTRY_CLOUD_HOST'),

        // Seconds a signed registration request stays valid.
        'signature_tolerance' => (int) env('REGISTRY_CLOUD_SIGNATURE_TOLERANCE', 300),
    ],

    'maintenance' => [
        // Refuse publishing and checkout with 503 (cutover freeze). `registry:maintenance on`
        // sets it at runtime without a deploy; this variable forces it on.
        'enabled' => (bool) env('REGISTRY_MAINTENANCE', false),
        'message' => env('REGISTRY_MAINTENANCE_MESSAGE', 'The Fleetbase extensions registry is being upgraded. Publishing and purchases are paused and will be back shortly.'),
        // Retry-After header on refused requests, in seconds.
        'retry_after' => (int) env('REGISTRY_MAINTENANCE_RETRY_AFTER', 3600),
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

    'commerce' => [
        // Facilitator fee in basis points (1000 = 10%) when no row in `facilitator_fees` is in
        // effect yet. Staff change the fee with POST /v1/admin/facilitator-fees, which keeps history.
        'facilitator_fee_bps' => (int) env('REGISTRY_FACILITATOR_FEE_BPS', 1000),

        // Seats (instances) on a paid licence (D4). Staff raise it per licence on request.
        'max_activations' => (int) env('REGISTRY_LICENCE_MAX_ACTIVATIONS', 5),

        // Stripe Tax on Checkout. The publisher's connected account is liable (the charge is
        // made on its behalf), so the tax collected reaches the account that files it.
        'tax' => [
            'enabled' => (bool) env('REGISTRY_STRIPE_TAX', true),
            // Product tax code for extensions; null uses the Stripe account's default.
            'code' => env('REGISTRY_STRIPE_TAX_CODE'),
            // Whether listing prices exclude tax (added at checkout) or include it.
            'behavior' => env('REGISTRY_STRIPE_TAX_BEHAVIOR', 'exclusive'),
        ],

        // Minutes a Checkout Session stays open (Stripe allows 30 to 1440).
        'checkout_expires_minutes' => (int) env('REGISTRY_CHECKOUT_EXPIRES_MINUTES', 60),

        // Country for new Connect Express accounts; null lets the publisher choose in onboarding.
        'connect_country' => env('REGISTRY_CONNECT_COUNTRY'),

        // Who hears about new disputes (a comma-separated list of emails); empty sends nothing.
        'notify' => array_values(array_filter(array_map('trim', explode(',', (string) env('REGISTRY_COMMERCE_NOTIFY', ''))))),
    ],

];
