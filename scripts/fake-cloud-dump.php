<?php

/**
 * A seeded fake of the cloud API's registry tables, for rehearsing the cutover (docs/cutover.md).
 *
 *   php scripts/fake-cloud-dump.php --out=storage/rehearsal/cloud.sql --files=storage/rehearsal/files [--scale=1] [--seed=42]
 *
 * Writes a MySQL dump with the schema of tests/Fixtures/import/cloud-registry.sql (the tables `registry:import`
 * reads) and the objects its `files` rows point at (bundles, icons, screenshots) under --files, with the same
 * paths. The same seed always gives the same dump. At --scale=1 it is sized above today's cloud registry (tens of
 * extensions, a few thousand companies with installs) so rehearsal timings carry headroom, and it includes every
 * anomaly class the importer reports: listings without bundles or package names, a package name used twice,
 * missing tarballs, a bundle without a version, duplicate versions, users without email, a paid install without
 * a purchase, refunded (deleted) purchases, uninstalled and duplicate installs.
 *
 * No real data: names, emails and Stripe ids are generated. Never point it at a real database.
 */
$options = getopt('', ['out:', 'files:', 'scale::', 'seed::', 'bundle-kb::']);
$out = $options['out'] ?? null;
$filesDir = $options['files'] ?? null;
if (! $out || ! $filesDir) {
    fwrite(STDERR, "Usage: php scripts/fake-cloud-dump.php --out=cloud.sql --files=dir [--scale=1] [--seed=42] [--bundle-kb=300]\n");
    exit(2);
}

$scale = max(0.1, (float) ($options['scale'] ?? 1));
$seed = (int) ($options['seed'] ?? 42);
$bundleKb = max(1, (int) ($options['bundle-kb'] ?? 300));
mt_srand($seed);

$n = fn (int $base) => max(1, (int) round($base * $scale));
$counts = [
    'categories' => 12,
    'developers' => $n(120),
    'companies' => $n(2000),
    'extensions' => $n(80),
    'purchases' => $n(600),
    'installs' => $n(9000),
];

// ---------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------

/** A UUID v4 from the seeded generator, so the same seed gives the same dump. */
function fakeUuid(): string
{
    $r = fn (int $max = 0xFFFF) => mt_rand(0, $max);

    return sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', $r(), $r(), $r(), $r(0xFFF), $r(0x3FFF) | 0x8000, $r(), $r(), $r());
}

function pick(array $items)
{
    return $items[mt_rand(0, count($items) - 1)];
}

function chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

function ts(int $daysAgo, int $jitter = 0): string
{
    return date('Y-m-d H:i:s', strtotime('2026-10-20 03:00:00') - $daysAgo * 86400 - mt_rand(0, max(0, $jitter)));
}

function sqlValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    return "'".str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', '\\r'], (string) $value)."'";
}

function writeObject(string $dir, string $path, int $bytes): int
{
    $file = rtrim($dir, '/').'/'.$path;
    @mkdir(dirname($file), 0777, true);
    $h = fopen($file, 'wb');
    $left = $bytes;
    while ($left > 0) {
        $chunk = min($left, 65536);
        fwrite($h, random_bytes($chunk));
        $left -= $chunk;
    }
    fclose($h);

    return $bytes;
}

$words = ['fleet', 'route', 'cargo', 'dock', 'yard', 'pallet', 'driver', 'parcel', 'freight', 'depot', 'transit', 'ledger', 'invoice', 'telematics', 'fuel', 'shift', 'dispatch', 'track', 'customs', 'cold'];
$suffix = ['insights', 'planner', 'sync', 'hub', 'pro', 'connect', 'manager', 'tools', 'bridge', 'scan', 'board', 'flow'];

// ---------------------------------------------------------------------------------------------
// Rows
// ---------------------------------------------------------------------------------------------

$rows = array_fill_keys(['companies', 'users', 'files', 'categories', 'registry_developer_accounts', 'registry_users', 'registry_extensions', 'registry_extension_bundles', 'registry_extension_purchases', 'registry_extension_installs'], []);
$id = array_fill_keys(array_keys($rows), 0);
$add = function (string $table, array $row) use (&$rows, &$id) {
    $rows[$table][] = ['id' => ++$id[$table]] + $row;
};
$bcrypt = '$2y$10$'.str_repeat('a', 53); // never a real password

for ($i = 0; $i < $counts['categories']; $i++) {
    $name = ucfirst($words[$i]).' '.ucfirst($suffix[$i % count($suffix)]);
    $add('categories', ['uuid' => fakeUuid(), 'public_id' => 'category_'.$i, 'name' => $name, 'internal_id' => strtolower(str_replace(' ', '-', $name)), 'description' => null, 'tags' => null, 'icon' => null, 'for' => 'extension_category', 'slug' => strtolower(str_replace(' ', '-', $name)), 'created_at' => ts(900), 'updated_at' => ts(900)]);
}
$categoryUuids = array_column($rows['categories'], 'uuid');

$companies = [];
for ($i = 0; $i < $counts['companies']; $i++) {
    $uuid = fakeUuid();
    $owner = fakeUuid();
    $name = ucfirst(pick($words)).' '.pick(['Logistics', 'Freight', 'Transport', 'Express', 'Couriers', 'Haulage']).' '.($i + 1);
    $companies[] = ['uuid' => $uuid, 'owner' => $owner];
    $add('companies', ['uuid' => $uuid, 'public_id' => 'company_'.$i, 'stripe_connect_id' => $i < 40 ? 'acct_fake'.str_pad((string) $i, 8, '0', STR_PAD_LEFT) : null, 'owner_uuid' => $owner, 'name' => $name, 'website_url' => null, 'description' => null, 'slug' => strtolower(str_replace(' ', '-', $name)), 'created_at' => ts(1000, 86400 * 600), 'updated_at' => ts(10), 'deleted_at' => $i % 250 === 249 ? ts(30) : null]);
    $add('users', ['uuid' => $owner, 'public_id' => 'user_'.$i, 'company_uuid' => $uuid, 'username' => 'owner'.$i, 'email' => $i % 500 === 499 ? null : "owner{$i}@company{$i}.example", 'password' => $bcrypt, 'name' => 'Owner '.$i, 'timezone' => 'UTC', 'status' => 'active', 'email_verified_at' => ts(900), 'created_at' => ts(900), 'updated_at' => ts(10), 'deleted_at' => null]);
}

// Publishing companies: the first 25 publish; each has a registry user (one row per token).
$publishers = array_slice($companies, 0, min(25, count($companies)));
foreach ($publishers as $j => $company) {
    $add('registry_users', ['uuid' => fakeUuid(), 'account_type' => 'cloud', 'developer_account_uuid' => null, 'company_uuid' => $company['uuid'], 'user_uuid' => $company['owner'], 'public_id' => 'ru_'.$j, 'token' => bin2hex(random_bytes(8)), 'registry_token' => null, 'scope' => null, 'expires_at' => null, 'last_used_at' => null, 'name' => null, 'meta' => null, 'revoked' => 0, 'created_at' => ts(700), 'updated_at' => ts(700), 'deleted_at' => null]);
}
// Cloud users who logged in to the registry to buy (registry_users of buying companies).
for ($j = 0; $j < $n(800); $j++) {
    $company = $companies[mt_rand(25, count($companies) - 1)];
    $add('registry_users', ['uuid' => fakeUuid(), 'account_type' => 'cloud', 'developer_account_uuid' => null, 'company_uuid' => $company['uuid'], 'user_uuid' => $company['owner'], 'public_id' => 'rub_'.$j, 'token' => bin2hex(random_bytes(8)), 'registry_token' => null, 'scope' => null, 'expires_at' => null, 'last_used_at' => null, 'name' => null, 'meta' => null, 'revoked' => 0, 'created_at' => ts(500), 'updated_at' => ts(500), 'deleted_at' => null]);
}

$developers = [];
for ($i = 0; $i < $counts['developers']; $i++) {
    $uuid = fakeUuid();
    $developers[] = $uuid;
    $add('registry_developer_accounts', ['uuid' => $uuid, 'username' => 'dev'.$i, 'email' => "dev{$i}@indie.example", 'password' => $bcrypt, 'name' => 'Developer '.$i, 'avatar_url' => null, 'github_username' => chance(0.5) ? 'dev-gh-'.$i : null, 'website' => null, 'bio' => null, 'email_verified_at' => $i % 7 === 6 ? null : ts(300), 'verification_token' => null, 'status' => $i % 7 === 6 ? 'pending_verification' : 'active', 'created_at' => ts(300, 86400 * 200), 'updated_at' => ts(10), 'deleted_at' => null]);
}

// Extensions, their bundles and the objects behind them.
$extensions = [];
$objectBytes = 0;
$missingObjects = 0;
for ($i = 0; $i < $counts['extensions']; $i++) {
    $uuid = fakeUuid();
    $byDeveloper = $i % 3 === 2;
    $publisher = $byDeveloper ? pick($developers) : $publishers[$i % count($publishers)]['uuid'];
    $slug = $words[$i % count($words)].'-'.$suffix[intdiv($i, count($words)) % count($suffix)].($i >= count($words) * count($suffix) ? '-'.$i : '');
    $scope = $byDeveloper ? 'dev'.array_search($publisher, $developers) : 'pub'.($i % count($publishers));
    $paid = $i % 10 < 3;
    $status = match (true) {
        $i % 20 === 19 => 'pending',
        $i % 20 === 18 => 'rejected',
        $i % 20 === 17 => 'awaiting_review',
        $i % 40 === 16 => 'unpublished',
        default => 'published',
    };
    $noBundles = $i % 40 === 39;               // a listing that never uploaded anything
    $deleted = $i % 25 === 24;
    $npmName = '@'.$scope.'/'.$slug.'-engine';
    if ($i === 5 && count($extensions) > 1) {
        $npmName = $extensions[1]['npm'];       // a package name claimed twice
    }

    $iconUuid = fakeUuid();
    $iconPath = "uploads/{$scope}/icons/{$slug}.png";
    $add('files', ['uuid' => $iconUuid, 'public_id' => 'file_icon_'.$i, 'company_uuid' => null, 'subject_uuid' => $uuid, 'disk' => 's3', 'path' => $iconPath, 'subject_type' => 'Fleetbase\\RegistryBridge\\Models\\RegistryExtension', 'bucket' => 'flb-cloud', 'original_filename' => $slug.'.png', 'type' => 'extension_icon', 'content_type' => 'image/png', 'file_size' => 2048, 'created_at' => ts(600), 'updated_at' => ts(600), 'deleted_at' => null]);
    $objectBytes += writeObject($filesDir, $iconPath, 2048);
    for ($s = 0; $s < 2; $s++) {
        $path = "uploads/{$scope}/screens/{$slug}-{$s}.png";
        $add('files', ['uuid' => fakeUuid(), 'public_id' => "file_shot_{$i}_{$s}", 'company_uuid' => null, 'subject_uuid' => $uuid, 'disk' => 's3', 'path' => $path, 'subject_type' => 'Fleetbase\\RegistryBridge\\Models\\RegistryExtension', 'bucket' => 'flb-cloud', 'original_filename' => basename($path), 'type' => 'extension_screenshot', 'content_type' => 'image/png', 'file_size' => 40960, 'created_at' => ts(600), 'updated_at' => ts(600), 'deleted_at' => null]);
        $objectBytes += writeObject($filesDir, $path, 40960);
    }

    $bundleUuids = [];
    $bundleCount = $noBundles ? 0 : mt_rand(3, 9);
    for ($b = 0; $b < $bundleCount; $b++) {
        $version = '1.'.intdiv($b, 3).'.'.($b % 3);
        $fileUuid = fakeUuid();
        $path = "uploads/{$scope}/extensions/{$slug}-{$version}.tar.gz";
        $bytes = (int) ($bundleKb * 1024 * (0.5 + mt_rand() / mt_getrandmax()));
        $missing = ($i * 7 + $b) % 50 === 0;     // ~2% of tarballs are gone from the bucket
        $add('files', ['uuid' => $fileUuid, 'public_id' => "file_b_{$i}_{$b}", 'company_uuid' => null, 'subject_uuid' => $uuid, 'disk' => 's3', 'path' => $path, 'subject_type' => 'Fleetbase\\RegistryBridge\\Models\\RegistryExtension', 'bucket' => 'flb-cloud', 'original_filename' => basename($path), 'type' => 'extension_bundle', 'content_type' => 'application/gzip', 'file_size' => $bytes, 'created_at' => ts(500 - $b * 20), 'updated_at' => ts(500 - $b * 20), 'deleted_at' => null]);
        if ($missing) {
            $missingObjects++;
        } else {
            $objectBytes += writeObject($filesDir, $path, $bytes);
        }

        $bundleUuid = fakeUuid();
        $bundleUuids[] = $bundleUuid;
        $last = $b === $bundleCount - 1;
        $bundleStatus = $last ? ($status === 'published' || $status === 'unpublished' ? 'published' : ($status === 'rejected' ? 'rejected' : 'pending')) : (chance(0.1) ? 'rejected' : 'published');
        $meta = [
            'extension.json' => array_filter(['name' => ucfirst(str_replace('-', ' ', $slug)), 'version' => $version, 'compat' => $b > 2 ? ['console' => '>=0.7.0', 'api' => '^1.6'] : null]),
            'package.json' => ['name' => $npmName, 'version' => $version],
            'composer.json' => ['name' => $scope.'/'.$slug.'-api'],
        ];
        if ($i % 30 === 3 && $b === 0) {
            $meta = null;                         // a bundle without any version
        }
        $add('registry_extension_bundles', ['uuid' => $bundleUuid, 'public_id' => "bundle_{$i}_{$b}", 'bundle_id' => strtoupper(substr(md5($bundleUuid), 0, 14)), 'company_uuid' => $byDeveloper ? null : $publisher, 'created_by_uuid' => null, 'extension_uuid' => $uuid, 'bundle_uuid' => $fileUuid, 'bundle_number' => (string) ($b + 1), 'version' => $meta === null ? null : $version, 'status' => $bundleStatus, 'meta' => $meta, 'created_at' => ts(500 - $b * 20), 'updated_at' => ts(499 - $b * 20), 'deleted_at' => null]);
    }
    if ($bundleCount > 3 && $i % 15 === 4) {
        // the same version uploaded twice
        $last = end($rows['registry_extension_bundles']);
        $add('registry_extension_bundles', ['uuid' => fakeUuid()] + array_diff_key($last, ['id' => 1, 'uuid' => 1]) + ['created_at' => ts(50)]);
    }

    $price = $paid ? 1900 + 1000 * mt_rand(0, 8) : null;
    $add('registry_extensions', ['uuid' => $uuid, 'company_uuid' => $byDeveloper ? null : $publisher, 'publisher_type' => $byDeveloper ? 'developer' : 'cloud', 'publisher_uuid' => $publisher, 'created_by_uuid' => null, 'registry_user_uuid' => null, 'current_bundle_uuid' => $bundleUuids ? end($bundleUuids) : null, 'next_bundle_uuid' => null, 'icon_uuid' => $iconUuid, 'category_uuid' => pick($categoryUuids), 'public_id' => 'ext_'.$i, 'stripe_product_id' => $paid ? 'prod_fake'.$i : null, 'name' => ucfirst(str_replace('-', ' ', $slug)), 'subtitle' => 'Fake listing '.$i, 'self_managed' => $i % 13 === 12 ? 1 : 0, 'payment_required' => $paid ? 1 : 0, 'price' => $price, 'sale_price' => $paid && $i % 4 === 0 ? $price - 500 : null, 'on_sale' => 0, 'subscription_required' => 0, 'currency' => 'USD', 'slug' => $slug, 'version' => null, 'fa_icon' => null, 'description' => str_repeat('Lorem ipsum dolor sit amet. ', 20), 'promotional_text' => null, 'website_url' => null, 'repo_url' => null, 'support_url' => null, 'privacy_policy_url' => null, 'tos_url' => null, 'copyright' => null, 'primary_language' => 'php', 'tags' => ['fake', pick($words)], 'languages' => ['en'], 'meta' => null, 'core_extension' => 0, 'status' => $status, 'package_name' => $noBundles && $i % 80 !== 39 ? $npmName : null, 'published_at' => $status === 'published' ? ts(400) : null, 'accepted_at' => null, 'rejected_at' => null, 'created_at' => ts(600, 86400 * 100), 'updated_at' => ts(20), 'deleted_at' => $deleted ? ts(60) : null]);
    $extensions[] = ['uuid' => $uuid, 'paid' => $paid, 'live' => $status === 'published' && ! $deleted && ! $noBundles, 'npm' => $npmName, 'price' => $price];
}

$live = array_values(array_filter($extensions, fn ($e) => $e['live']));
$livePaid = array_values(array_filter($live, fn ($e) => $e['paid']));
$liveFree = array_values(array_filter($live, fn ($e) => ! $e['paid']));

// Purchases: companies (and a few developers) buying paid listings; some refunded (deleted).
$owned = [];
for ($i = 0; $i < $counts['purchases'] && $livePaid; $i++) {
    $extension = pick($livePaid);
    $developer = $i % 50 === 49;
    $buyer = $developer ? pick($developers) : $companies[mt_rand(25, count($companies) - 1)]['uuid'];
    if (! $developer) {
        $owned[$buyer][$extension['uuid']] = true;
    }
    $add('registry_extension_purchases', ['uuid' => fakeUuid(), 'purchaser_uuid' => $buyer, 'purchaser_type' => $developer ? 'Fleetbase\\RegistryBridge\\Models\\RegistryDeveloperAccount' : 'Fleetbase\\Models\\Company', 'company_uuid' => $developer ? null : $buyer, 'extension_uuid' => $extension['uuid'], 'stripe_checkout_session_id' => 'cs_fake_'.$i, 'stripe_payment_intent_id' => 'pi_fake_'.$i, 'is_subcription' => 0, 'locked_price' => $extension['price'], 'subscription_billing_period' => null, 'subscription_model' => null, 'meta' => null, 'created_at' => ts(300, 86400 * 250), 'updated_at' => ts(10), 'deleted_at' => $i % 40 === 39 ? ts(20) : null]);
}

// Installs: free listings widely, paid listings where the company bought them, plus a few anomalies.
for ($i = 0; $i < $counts['installs'] && $live; $i++) {
    $company = $companies[mt_rand(25, count($companies) - 1)]['uuid'];
    $ownedHere = array_keys($owned[$company] ?? []);
    $extension = $ownedHere && chance(0.6) ? ['uuid' => pick($ownedHere)] : ($liveFree ? pick($liveFree) : pick($live));
    if ($i % 400 === 399 && $livePaid) {
        $extension = pick($livePaid);          // paid install without a purchase
    }
    $add('registry_extension_installs', ['uuid' => fakeUuid(), 'company_uuid' => $company, 'extension_uuid' => $extension['uuid'], 'meta' => null, 'created_at' => ts(250, 86400 * 200), 'updated_at' => ts(5), 'deleted_at' => chance(0.15) ? ts(5, 86400 * 30) : null]);
}

// ---------------------------------------------------------------------------------------------
// Dump
// ---------------------------------------------------------------------------------------------

$fixture = file_get_contents(__DIR__.'/../tests/Fixtures/import/cloud-registry.sql');
$schema = implode("\n", array_filter(explode("\n", $fixture), fn ($line) => ! str_starts_with($line, 'INSERT INTO')));
$schema = preg_replace('/^-- Dump completed.*$/m', '', $schema);
$schema = str_replace('/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;', '', $schema);

@mkdir(dirname($out), 0777, true);
$h = fopen($out, 'wb');
fwrite($h, "-- Fake cloud registry dump for cutover rehearsals (scripts/fake-cloud-dump.php, seed {$seed}, scale {$scale}). Not real data.\n");
fwrite($h, $schema."\n");
foreach ($rows as $table => $tableRows) {
    foreach (array_chunk($tableRows, 500) as $chunk) {
        $columns = array_keys($chunk[0]);
        fwrite($h, 'INSERT INTO `'.$table.'` (`'.implode('`,`', $columns).'`) VALUES '.implode(',', array_map(fn ($row) => '('.implode(',', array_map('sqlValue', array_values($row))).')', $chunk)).";\n");
    }
}
fwrite($h, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n-- Dump completed\n");
fclose($h);

$summary = array_map('count', $rows) + ['objects_mb' => round($objectBytes / 1048576, 1), 'missing_tarballs' => $missingObjects, 'dump_mb' => round(filesize($out) / 1048576, 1)];
echo json_encode($summary, JSON_PRETTY_PRINT)."\n";
