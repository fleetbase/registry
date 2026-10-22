<?php

namespace App\Http\Controllers\Instance;

use App\Actions\ActivateLicence;
use App\Actions\DeactivateLicence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instance\ReportInstallRequest;
use App\Http\Resources\InstallResource;
use App\Http\Resources\LicenceActivationResource;
use App\Models\Event;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Install telemetry from instances. One `installs` row per install attempt:
 * `installing` opens a row, `installed` or `failed` closes it. Once a release is
 * installed, earlier installed rows for the same extension become `uninstalled`, so an
 * instance counts once per extension in the catalog's install counts.
 */
class InstallController extends Controller
{
    /**
     * POST /v1/instances/me/installs — report an install start, success or failure.
     * Start and success claim a licence seat (creating the free licence when needed) and
     * return the activation; a failure releases the seat unless an earlier release of
     * the extension is still installed.
     */
    public function store(ReportInstallRequest $request, ActivateLicence $activate, DeactivateLicence $deactivate): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();
        $status = $request->input('status');

        $extension = $this->resolveExtension($request->input('extension'));
        if (! $extension) {
            return response()->error('Extension not found.', 404);
        }

        $release = $extension->releases()
            ->where(fn ($q) => $request->filled('release')
                ? $q->where('public_id', $request->input('release'))->orWhere('uuid', $request->input('release'))
                : $q->where('version', ltrim((string) $request->input('version'), 'vV')))
            ->first();

        if (! $release) {
            return response()->error('Release not found for this extension.', 404);
        }

        if ($status === Install::STATUS_INSTALLING && ($error = $this->installRefusal($instance, $extension, $release))) {
            return response()->error($error, 422);
        }

        if (! in_array($release->status, [Release::STATUS_PUBLISHED, Release::STATUS_YANKED], true)) {
            return response()->error('Only published releases can be installed.', 422);
        }

        $activation = $status === Install::STATUS_FAILED ? null : $activate($instance, $extension, $release);

        [$install, $created] = DB::transaction(function () use ($instance, $extension, $release, $status, $request) {
            $open = Install::query()
                ->where('instance_uuid', $instance->uuid)
                ->where('extension_uuid', $extension->uuid)
                ->where('status', Install::STATUS_INSTALLING)
                ->orderByDesc('id')
                ->first();

            if ($status === Install::STATUS_INSTALLING && $open) {
                $open->forceFill(['status' => Install::STATUS_FAILED, 'error' => 'Superseded by a newer install attempt.', 'finished_at' => now()])->save();
                $open = null;
            }

            $install = $open ?? new Install([
                'instance_uuid' => $instance->uuid,
                'extension_uuid' => $extension->uuid,
                'started_at' => now(),
            ]);
            $created = ! $install->exists;

            $install->fill([
                'release_uuid' => $release->uuid,
                'status' => $status,
                'installed_by' => $request->input('installed_by', $install->installed_by),
                'error' => $status === Install::STATUS_FAILED ? $request->input('error') : null,
                'finished_at' => $status === Install::STATUS_INSTALLING ? null : now(),
            ])->save();

            if ($status === Install::STATUS_INSTALLED) {
                Install::query()
                    ->where('instance_uuid', $instance->uuid)
                    ->where('extension_uuid', $extension->uuid)
                    ->where('status', Install::STATUS_INSTALLED)
                    ->whereKeyNot($install->getKey())
                    ->update(['status' => Install::STATUS_UNINSTALLED, 'finished_at' => now(), 'updated_at' => now()]);
            }

            return [$install, $created];
        });

        if ($status === Install::STATUS_FAILED && ! $this->hasInstalled($instance, $extension)) {
            $deactivate($instance, $extension);
        }

        Event::record('install.'.$status, $install, $instance, ['extension' => $extension->public_id, 'version' => $release->version], $request->ip());

        return response()->json([
            'install' => (new InstallResource($install->load(['extension', 'release'])))->resolve($request),
            'activation' => $activation ? (new LicenceActivationResource($activation->load('release')))->resolve($request) : null,
        ], $created ? 201 : 200);
    }

    /**
     * DELETE /v1/instances/me/installs/{extension} — report an uninstall and release
     * the instance's licence seat.
     */
    public function destroy(Request $request, string $extension, DeactivateLicence $deactivate): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();

        $model = $this->resolveExtension($extension);
        if (! $model) {
            return response()->error('Extension not found.', 404);
        }

        $installs = Install::query()
            ->where('instance_uuid', $instance->uuid)
            ->where('extension_uuid', $model->uuid)
            ->whereIn('status', [Install::STATUS_INSTALLED, Install::STATUS_INSTALLING])
            ->orderByDesc('id')
            ->get();

        if ($installs->isEmpty()) {
            return response()->error('This extension is not installed on this instance.', 404);
        }

        Install::query()->whereKey($installs->modelKeys())->update([
            'status' => Install::STATUS_UNINSTALLED,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
        $deactivated = $deactivate($instance, $model);

        $install = $installs->first()->refresh()->load(['extension', 'release']);
        Event::record('install.uninstalled', $install, $instance, ['extension' => $model->public_id], $request->ip());

        return response()->json([
            'install' => (new InstallResource($install))->resolve($request),
            'deactivated' => $deactivated,
        ]);
    }

    /**
     * Extensions are addressed by slug, public id, uuid, or one of their npm or
     * Composer package names (what an instance knows from its installed packages).
     */
    protected function resolveExtension(string $identifier): ?Extension
    {
        $identifier = urldecode($identifier);

        $extension = Extension::query()
            ->where(fn ($q) => $q->where('slug', $identifier)->orWhere('public_id', $identifier))
            ->when(Str::isUuid($identifier), fn ($q) => $q->orWhere('uuid', $identifier))
            ->first();

        if ($extension) {
            return $extension;
        }

        $package = ExtensionPackage::query()->where('name', $identifier)->first();

        return $package ? Extension::find($package->extension_uuid) : null;
    }

    /**
     * Why a new install may not start, or null when it may.
     */
    protected function installRefusal(Instance $instance, Extension $extension, Release $release): ?string
    {
        if ($extension->status !== Extension::STATUS_PUBLISHED) {
            return 'This extension is not published.';
        }

        if (! $release->isPublished()) {
            return 'Only published releases can be installed.';
        }

        if ($extension->hosting !== Extension::HOSTING_BOTH && $extension->hosting !== $instance->kind) {
            return "This extension is only available for {$extension->hosting} instances.";
        }

        return null;
    }

    protected function hasInstalled(Instance $instance, Extension $extension): bool
    {
        return Install::query()
            ->where('instance_uuid', $instance->uuid)
            ->where('extension_uuid', $extension->uuid)
            ->where('status', Install::STATUS_INSTALLED)
            ->exists();
    }
}
