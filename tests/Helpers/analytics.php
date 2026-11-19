<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Release;
use Illuminate\Support\Carbon;

/**
 * A published listing with releases 1.0.0 and 1.1.0, and an account with `$role` in its
 * organization. Returns [account, organization, listing, [1.0.0, 1.1.0]].
 */
function statsListing(string $role = Membership::ROLE_OWNER, ?Organization $organization = null, array $extension = []): array
{
    $account = Account::factory()->create();
    $organization ??= Organization::factory()->create();
    $account->organizations()->attach($organization->uuid, ['role' => $role]);

    $listing = Extension::factory()->published()->free()->withPackages()->for($organization)->state($extension)->create();
    $releases = collect(['1.0.0', '1.1.0'])->map(fn (string $version) => Release::factory()->published()->create([
        'extension_uuid' => $listing->uuid,
        'version' => $version,
        'compat' => [],
    ]))->all();

    return [$account, $organization, $listing, $releases];
}

/**
 * An install row as InstallController writes it: installed at `$at` on a new instance of a new
 * organization (or `$instance`), then optionally ended (`uninstalled_at` / `replaced_at`).
 */
function statsInstall(Extension $listing, Release $release, string $at, array $attributes = [], ?Instance $instance = null): Install
{
    $instance ??= Instance::factory()->create();
    $ended = isset($attributes['uninstalled_at']) || isset($attributes['replaced_at']);

    return Install::factory()->create(array_merge([
        'instance_uuid' => $instance->uuid,
        'extension_uuid' => $listing->uuid,
        'release_uuid' => $release->uuid,
        'status' => $ended ? Install::STATUS_UNINSTALLED : Install::STATUS_INSTALLED,
        'started_at' => Carbon::parse($at)->subMinute(),
        'finished_at' => $attributes['uninstalled_at'] ?? $attributes['replaced_at'] ?? $at,
        'installed_at' => $at,
    ], $attributes));
}

/**
 * An update on the install's instance at `$at`: the old row is replaced and a new row carries
 * the release it replaced.
 */
function statsUpdate(Install $install, Release $release, string $at): Install
{
    $install->forceFill(['status' => Install::STATUS_UNINSTALLED, 'finished_at' => $at, 'replaced_at' => $at])->save();

    return statsInstall($install->extension()->firstOrFail(), $release, $at, ['previous_release_uuid' => $install->release_uuid], $install->instance()->firstOrFail());
}
