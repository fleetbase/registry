<?php

namespace App\Http\Controllers\Instance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instance\RegisterCloudInstanceRequest;
use App\Http\Requests\Instance\RegisterInstanceRequest;
use App\Http\Resources\ExtensionReferenceResource;
use App\Http\Resources\InstanceResource;
use App\Http\Resources\LicenceResource;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\ReleaseResource;
use App\Models\Event;
use App\Models\Install;
use App\Models\Instance;
use App\Models\InstanceRegistrationCode;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Organization;
use App\Models\Release;
use App\Support\CloudPlatform;
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
     *
     * Registering an instance that is already registered rotates it (D17): the same row is updated
     * and handed a new token (the old one stops working), so its installs and licence seats stay
     * on one instance, and the response is 200 with `rotated: true`. The instance is matched by
     *
     * 1. `previous_token`, the instance's current token, when it belongs to an active instance in
     *    the code's organization; else
     * 2. `host`, when an active instance of the code's organization has the same host (scheme,
     *    case and a trailing slash ignored).
     *
     * A `previous_token` of another organization's instance creates a new instance; the old one is
     * revoked with its seats released and its open installs move to the new instance. Anything
     * else (no match, an unknown or revoked token, a new host) creates a new instance: 201.
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

        $previous = $request->filled('previous_token') ? Instance::findByToken((string) $request->input('previous_token')) : null;
        $matched = $previous !== null && $previous->organization_uuid === $organization->uuid ? 'token' : null;
        if ($previous === null && ($previous = Instance::findByHost($organization->uuid, $request->input('host')))) {
            $matched = 'host';
        }

        [$instance, $plain] = $this->issue($organization, [
            'name' => $request->input('name'),
            'host' => $request->input('host'),
            'kind' => $request->input('kind'),
            'console_version' => $request->input('console_version'),
            'api_version' => $request->input('api_version'),
        ], $previous, $matched !== null, $code->account_uuid, fn (Instance $instance) => $code->forceFill(['instance_uuid' => $instance->uuid])->save());

        Event::record($matched ? 'instance.reregistered' : 'instance.registered', $instance, $code->account, array_filter([
            'code' => $code->uuid,
            'matched_by' => $matched,
            'replaced' => $previous && ! $matched ? $previous->public_id : null,
        ]), $request->ip());

        return $this->registered($request, $instance, $plain, $matched !== null);
    }

    /**
     * POST /v1/instances/register/cloud — the cloud console's API registers itself on boot,
     * signed with the platform secret (`platform` middleware) instead of a registration code.
     *
     * One instance per cloud deployment, kind `cloud`, owned by `registry.cloud.organization`; it
     * acts for each cloud company through `X-Registry-Tenant`. Idempotent across restarts and
     * replicas by the same rotation rules (D17): it is matched by `previous_token`, else by the
     * same organization and host, else it adopts the placeholder `registry:import` created that
     * never had a token. Matched: 200 with `rotated: true`; otherwise a new instance, 201.
     */
    public function registerCloud(RegisterCloudInstanceRequest $request): JsonResponse
    {
        $organization = CloudPlatform::organization();

        $previous = $request->filled('previous_token') ? Instance::findByToken((string) $request->input('previous_token')) : null;
        $matched = $previous !== null && $previous->isPlatform() && $previous->organization_uuid === $organization->uuid ? 'token' : null;
        if ($matched === null) {
            $previous = CloudPlatform::instance($organization, $request->input('host'), anyHost: false);
            $matched = match (true) {
                $previous === null => null,
                Instance::normalizeHost($previous->host) === Instance::normalizeHost($request->input('host')) => 'host',
                default => 'placeholder',
            };
        }

        [$instance, $plain] = $this->issue($organization, [
            'name' => $request->input('name') ?: 'Fleetbase Cloud',
            'host' => $request->input('host'),
            'kind' => Instance::KIND_CLOUD,
            'console_version' => $request->input('console_version'),
            'api_version' => $request->input('api_version'),
            'meta' => array_merge($previous?->meta ?? [], ['platform' => true]),
        ], $matched ? $previous : null, $matched !== null);

        Event::record($matched ? 'instance.reregistered' : 'instance.registered', $instance, null, array_filter([
            'platform' => true,
            'matched_by' => $matched,
        ]), $request->ip());

        return $this->registered($request, $instance, $plain, $matched !== null);
    }

    /**
     * Rotate `$previous` (when `$rotate`) or create the instance, and issue its token.
     *
     * @param  array<string, mixed>  $attributes
     * @param  (callable(Instance): mixed)|null  $issued  runs in the same transaction
     * @return array{0: Instance, 1: string}
     */
    protected function issue(Organization $organization, array $attributes, ?Instance $previous, bool $rotate, ?string $registeredBy = null, ?callable $issued = null): array
    {
        return DB::transaction(function () use ($organization, $attributes, $previous, $rotate, $registeredBy, $issued) {
            $attributes += ['status' => Instance::STATUS_ACTIVE, 'last_seen_at' => now()];

            if ($rotate) {
                $instance = tap($previous)->update($attributes);
            } else {
                $instance = Instance::create($attributes + [
                    'organization_uuid' => $organization->uuid,
                    'registered_by_account_uuid' => $registeredBy,
                ]);

                if ($previous) {
                    $this->replace($previous, $instance);
                }
            }

            $plain = $instance->issueToken();
            if ($issued) {
                $issued($instance);
            }

            return [$instance, $plain];
        });
    }

    protected function registered(Request $request, Instance $instance, string $plain, bool $rotated): JsonResponse
    {
        return response()->json([
            'token' => $plain,
            'token_type' => 'Bearer',
            'rotated' => $rotated,
            'instance' => (new InstanceResource($instance->load('organization')))->resolve($request),
        ], $rotated ? 200 : 201);
    }

    /**
     * GET /v1/instances/me — the instance, its organization and the organization's
     * licences with seat usage, whether this instance holds a seat, and the receipt of a
     * bought licence (so a console can link it after a re-registration or a cache clear).
     * The cloud instance acting for a company gets that company's licences, and `tenant`.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();

        $licences = Licence::query()
            ->where('organization_uuid', $instance->licensingOrganizationUuid())
            ->with(['extension', 'purchase'])
            ->withCount('activeActivations')
            ->addSelect(['activated_here' => LicenceActivation::query()
                ->selectRaw('count(*)')
                ->whereColumn('licence_activations.licence_uuid', 'licences.uuid')
                ->where('licence_activations.instance_uuid', $instance->uuid)
                ->whereNull('licence_activations.deactivated_at'),
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(array_filter([
            'instance' => (new InstanceResource($instance->load('organization')))->resolve($request),
            'tenant' => $instance->tenant() ? (new OrganizationResource($instance->tenant()))->resolve($request) : null,
            'licences' => LicenceResource::collection($licences)->resolve($request),
        ], fn ($value) => $value !== null));
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
            ->where('organization_uuid', $instance->licensingOrganizationUuid())
            ->where('status', Install::STATUS_INSTALLED)
            ->with(['extension', 'release.engineDist'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Install $install) => $install->extension !== null);

        $published = Release::query()
            ->published()
            ->whereIn('extension_uuid', $installs->pluck('extension_uuid')->unique())
            ->whereIn('channel', $channels)
            ->with('engineDist')
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

    /**
     * GET /v1/instances/me/tenant-installs — every extension installed on the cloud instance, per
     * cloud company, 500 a page (`?page=`, `?per_page=` up to 500). The cloud console's API reads it once after the
     * cutover import (`registry:cloud-sync-installs`) to record locally what each company had
     * installed, so their consoles keep loading those engines. Cloud instance only.
     */
    public function tenantInstalls(Request $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();
        if (! $instance->isPlatform()) {
            return response()->error('Only the cloud instance lists installs per company.', 403);
        }

        $page = Install::query()
            ->where('instance_uuid', $instance->uuid)
            ->where('status', Install::STATUS_INSTALLED)
            ->whereNotNull('organization_uuid')
            ->with(['organization', 'extension.packages', 'release'])
            ->orderBy('id')
            ->paginate(min(500, max(1, (int) $request->input('per_page', 500))));

        $data = collect($page->items())
            ->filter(fn (Install $install) => $install->extension !== null && $install->organization !== null)
            ->map(fn (Install $install) => [
                'company_uuid' => $install->organization->source_uuid,
                'organization' => $install->organization->public_id,
                'extension' => (new ExtensionReferenceResource($install->extension))->resolve($request),
                'packages' => $install->extension->packages->mapWithKeys(fn ($package) => [$package->kind => $package->name])->all(),
                'release' => $install->release ? ['id' => $install->release->public_id, 'version' => $install->release->version] : null,
                'installed_at' => $install->installed_at,
            ])
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Retire an instance that re-registered into another organization: revoke it, release the
     * seats it held on its old organization's licences and move its open installs to the new
     * instance, which claims seats in its own organization on its next install report.
     */
    protected function replace(Instance $previous, Instance $instance): void
    {
        $previous->forceFill(['status' => Instance::STATUS_REVOKED])->save();

        LicenceActivation::query()
            ->where('instance_uuid', $previous->uuid)
            ->whereNull('deactivated_at')
            ->update(['deactivated_at' => now(), 'updated_at' => now()]);

        Install::query()
            ->where('instance_uuid', $previous->uuid)
            ->whereIn('status', [Install::STATUS_INSTALLING, Install::STATUS_INSTALLED])
            ->update(['instance_uuid' => $instance->uuid, 'organization_uuid' => $instance->organization_uuid, 'updated_at' => now()]);
    }
}
