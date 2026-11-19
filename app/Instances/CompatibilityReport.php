<?php

namespace App\Instances;

use App\Http\Resources\ExtensionReferenceResource;
use App\Http\Resources\ReleaseResource;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Release;
use App\Support\Compat;
use App\Support\Semver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Services\ExtensionAccess;
use App\Services\Viewer;

/**
 * Would the extensions installed on an instance still work on another core version?
 *
 * For a target console and API version (the versions a Fleetbase release tag ships), every
 * extension installed on the instance gets the newest published release whose `compat` ranges
 * accept the target (`Compat::releaseIsCompatible`, the same rule as `me/updates`), whether the
 * installed release itself accepts it, and an `action`:
 *
 * - `keep`: the installed release works on the target as it is;
 * - `update`: it does not, a newer release does (install it with or after the core upgrade);
 * - `downgrade`: it does not, only an older release does;
 * - `blocked`: no published release works on the target.
 *
 * Only `blocked` blocks an upgrade. With host module versions (`host`, the `@fleetbase/ember-ui`
 * and `@fleetbase/ember-core` the target console ships), the chosen release's prebuilt dist is
 * also checked against them (`Compat::hostModules`). That never blocks: an installer rebuilds
 * the engine from source when the dist does not fit.
 *
 * Private extensions (`extensions.visibility` other than `public`, when the column exists) are
 * only described to callers that may see them: staff, the publishing organization's instances
 * and instances whose organization holds an active licence. Other callers get the extension's
 * id and slug (which the instance reported itself) with `action: blocked`, `reason: no_access`
 * and no release data.
 */
class CompatibilityReport
{
    public const ACTION_KEEP = 'keep';

    public const ACTION_UPDATE = 'update';

    public const ACTION_DOWNGRADE = 'downgrade';

    public const ACTION_BLOCKED = 'blocked';

    /**
     * @param  array<string, string>  $host  host module versions of the target console
     */
    public function __construct(
        public readonly ?string $consoleVersion,
        public readonly ?string $apiVersion,
        public readonly array $host = [],
        public readonly string $policy = 'minor',
        public readonly bool $beta = false,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function for(Instance $instance, Request $request, bool $staff = false): array
    {
        $channels = $this->beta ? [Release::CHANNEL_STABLE, Release::CHANNEL_BETA] : [Release::CHANNEL_STABLE];

        $installs = Install::query()
            ->where('instance_uuid', $instance->uuid)
            ->where('status', Install::STATUS_INSTALLED)
            ->with(['extension', 'release.engineDist'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Install $install) => $install->extension !== null);

        $visible = $staff ? null : $this->visibleExtensions($instance, $installs->pluck('extension'));

        $published = Release::query()
            ->published()
            ->whereIn('extension_uuid', $installs->pluck('extension_uuid')->unique())
            ->whereIn('channel', $channels)
            ->with('engineDist')
            ->get()
            ->groupBy('extension_uuid');

        $data = $installs->map(function (Install $install) use ($published, $request, $visible) {
            $extension = $install->extension;

            if ($visible !== null && ! isset($visible[$extension->uuid])) {
                return [
                    'extension' => ['id' => $extension->public_id, 'slug' => $extension->slug, 'name' => null],
                    'installed' => null,
                    'installed_compatible' => null,
                    'compatible' => null,
                    'compatible_release_exists' => false,
                    'action' => self::ACTION_BLOCKED,
                    'reason' => 'no_access',
                    'dist' => null,
                ];
            }

            $compatible = ($published->get($install->extension_uuid) ?? collect())
                ->filter(fn (Release $release) => Compat::releaseIsCompatible($release, $this->consoleVersion, $this->apiVersion));
            $newest = Compat::latest($compatible);
            $installedWorks = $install->release !== null
                && Compat::releaseIsCompatible($install->release, $this->consoleVersion, $this->apiVersion);

            $action = match (true) {
                $installedWorks => self::ACTION_KEEP,
                $newest === null => self::ACTION_BLOCKED,
                Semver::isNewer($newest->version, $install->release?->version) => self::ACTION_UPDATE,
                default => self::ACTION_DOWNGRADE,
            };
            $target = $action === self::ACTION_KEEP ? $install->release : $newest;

            return [
                'extension' => (new ExtensionReferenceResource($extension))->resolve($request),
                'installed' => $install->release ? (new ReleaseResource($install->release))->resolve($request) : null,
                'installed_compatible' => $installedWorks,
                'compatible' => $newest ? (new ReleaseResource($newest))->resolve($request) : null,
                'compatible_release_exists' => $newest !== null,
                'action' => $action,
                'reason' => $action === self::ACTION_BLOCKED ? 'no_compatible_release' : null,
                'dist' => $this->distFor($target),
            ];
        })->values();

        $blocking = $data->where('action', self::ACTION_BLOCKED)->values();

        return [
            'data' => $data->all(),
            'meta' => [
                'target' => array_filter([
                    'console_version' => $this->consoleVersion,
                    'api_version' => $this->apiVersion,
                    'host' => $this->host ?: null,
                    'policy' => $this->host ? $this->policy : null,
                ], fn ($value) => $value !== null),
                'instance' => [
                    'id' => $instance->public_id,
                    'console_version' => $instance->console_version,
                    'api_version' => $instance->api_version,
                ],
                'channels' => $channels,
                'compatible' => $blocking->isEmpty(),
                'installed' => $data->count(),
                'blocking' => $blocking->count(),
                'blocking_extensions' => $blocking->pluck('extension.slug')->all(),
                'updates_required' => $data->whereIn('action', [self::ACTION_UPDATE, self::ACTION_DOWNGRADE])->count(),
            ],
        ];
    }

    /**
     * Whether the release's prebuilt dist fits the target's host modules; null with no dist, no
     * pins on it or no host versions given.
     *
     * @return array{built_against: array<string, mixed>, compatible: bool, reasons: list<string>}|null
     */
    protected function distFor(?Release $release): ?array
    {
        if ($release === null || $release->engineDist === null) {
            return null;
        }

        $builtAgainst = (array) $release->metaValue('dist.built_against', []);
        if ($builtAgainst === [] || $this->host === []) {
            return null;
        }

        return ['built_against' => $builtAgainst] + Compat::hostModules($builtAgainst, $this->host, $this->policy);
    }

    /**
     * Uuids of the installed extensions this instance may see, keyed by uuid, by the same rules as
     * the catalog (`ExtensionAccess::canSee`): public extensions always; a private one for its
     * owner's instances and for grants or paid licences that cover this instance.
     *
     * @param  Collection<int, Extension>  $extensions
     * @return array<string, true>
     */
    protected function visibleExtensions(Instance $instance, Collection $extensions): array
    {
        $access = app(ExtensionAccess::class);
        $viewer = Viewer::forInstance($instance);

        return $extensions
            ->filter(fn (Extension $extension) => $access->canSee($viewer, $extension))
            ->mapWithKeys(fn (Extension $extension) => [$extension->uuid => true])
            ->all();
    }

    public static function isPrivate(Extension $extension): bool
    {
        return $extension->isPrivate();
    }
}
