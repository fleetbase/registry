<?php

namespace App\Http\Controllers\Instance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instance\RegisterInstanceRequest;
use App\Http\Resources\ExtensionReferenceResource;
use App\Http\Resources\InstanceResource;
use App\Http\Resources\LicenceResource;
use App\Http\Resources\ReleaseResource;
use App\Models\Event;
use App\Models\Install;
use App\Models\Instance;
use App\Models\InstanceRegistrationCode;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Organization;
use App\Models\Release;
use App\Support\Compat;
use App\Support\Semver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstanceController extends Controller
{
    /**
     * POST /v1/instances/register — exchanges a registration code for an instance token.
     * The token (`flbi_…`) is returned once; only its SHA-256 hash is stored.
     */
    public function register(RegisterInstanceRequest $request): JsonResponse
    {
        $code = InstanceRegistrationCode::redeem($request->input('code'));
        if (! $code) {
            return response()->error('The registration code is invalid, expired or already used.', 401);
        }

        $organization = Organization::find($code->organization_uuid);
        if (! $organization) {
            return response()->error('The organization for this registration code no longer exists.', 422);
        }

        [$instance, $plain] = DB::transaction(function () use ($request, $code, $organization) {
            $instance = Instance::create([
                'organization_uuid' => $organization->uuid,
                'registered_by_account_uuid' => $code->account_uuid,
                'name' => $request->input('name'),
                'host' => $request->input('host'),
                'kind' => $request->input('kind'),
                'console_version' => $request->input('console_version'),
                'api_version' => $request->input('api_version'),
                'status' => Instance::STATUS_ACTIVE,
                'last_seen_at' => now(),
            ]);

            $plain = $instance->issueToken();
            $code->forceFill(['instance_uuid' => $instance->uuid])->save();

            return [$instance, $plain];
        });

        Event::record('instance.registered', $instance, $code->account, ['code' => $code->uuid], $request->ip());

        return response()->json([
            'token' => $plain,
            'token_type' => 'Bearer',
            'instance' => (new InstanceResource($instance->load('organization')))->resolve($request),
        ], 201);
    }

    /**
     * GET /v1/instances/me — the instance, its organization and the organization's
     * licences with seat usage and whether this instance holds a seat.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();

        $licences = Licence::query()
            ->where('organization_uuid', $instance->organization_uuid)
            ->with('extension')
            ->withCount('activeActivations')
            ->addSelect(['activated_here' => LicenceActivation::query()
                ->selectRaw('count(*)')
                ->whereColumn('licence_activations.licence_uuid', 'licences.uuid')
                ->where('licence_activations.instance_uuid', $instance->uuid)
                ->whereNull('licence_activations.deactivated_at'),
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'instance' => (new InstanceResource($instance->load('organization')))->resolve($request),
            'licences' => LicenceResource::collection($licences)->resolve($request),
        ]);
    }

    /**
     * GET /v1/instances/me/updates?channel=stable|beta — every extension installed on
     * the instance with its installed release and the newest published release whose
     * `compat` ranges accept the instance's console and API versions.
     */
    public function updates(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();
        $channels = $request->input('channel') === Release::CHANNEL_BETA
            ? [Release::CHANNEL_STABLE, Release::CHANNEL_BETA]
            : [Release::CHANNEL_STABLE];

        $installs = Install::query()
            ->where('instance_uuid', $instance->uuid)
            ->where('status', Install::STATUS_INSTALLED)
            ->with(['extension', 'release'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Install $install) => $install->extension !== null);

        $published = Release::query()
            ->published()
            ->whereIn('extension_uuid', $installs->pluck('extension_uuid')->unique())
            ->whereIn('channel', $channels)
            ->get()
            ->groupBy('extension_uuid');

        $data = $installs->map(function (Install $install) use ($instance, $published, $request) {
            $compatible = ($published->get($install->extension_uuid) ?? collect())
                ->filter(fn (Release $release) => Compat::releaseIsCompatible($release, $instance->console_version, $instance->api_version));
            $latest = Compat::latest($compatible);

            return [
                'extension' => (new ExtensionReferenceResource($install->extension))->resolve($request),
                'installed' => $install->release ? (new ReleaseResource($install->release))->resolve($request) : null,
                'latest' => $latest ? (new ReleaseResource($latest))->resolve($request) : null,
                'update_available' => $latest !== null && Semver::isNewer($latest->version, $install->release?->version),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'console_version' => $instance->console_version,
                'api_version' => $instance->api_version,
                'channels' => $channels,
                'updates_available' => $data->where('update_available', true)->count(),
            ],
        ]);
    }
}
