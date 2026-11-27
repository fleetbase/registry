<?php

namespace App\Http\Controllers\Publisher;

use App\Access\Grants;
use App\Http\Resources\GrantResource;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who can see and install an extension: its visibility and the organizations it is granted to.
 *
 * Publisher routes (`/v1/publisher/extensions/{slug}/access`) take the owner or admin role in
 * the listing's organization; the same actions under `/v1/admin/extensions/{slug}/access` are
 * for registry staff on any listing.
 */
class AccessController extends PublisherController
{
    public function __construct(protected Grants $grants) {}

    /**
     * GET …/extensions/{slug}/access — `{visibility, pending_visibility, grants: [...]}`.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $extension = $this->extension($request, $slug);

        return response()->json([
            'extension' => ['id' => $extension->public_id, 'slug' => $extension->slug, 'name' => $extension->name],
            'visibility' => $extension->visibility,
            'pending_visibility' => $extension->pendingVisibility(),
            'grants' => GrantResource::collection($this->grants->for($extension))->resolve($request),
        ]);
    }

    /**
     * POST …/extensions/{slug}/access — `{organization, instances?, max_activations?, expires_at?, note?}`.
     * 201 with the grant; 409 when the organization already has an active grant.
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $extension = $this->extension($request, $slug);
        $input = $request->validate(array_merge(['organization' => ['required', 'string', 'max:191']], self::rules()));

        $organization = $this->organizationByIdentifier($input['organization'])
            ?? throw ValidationException::withMessages(['organization' => "No organization {$input['organization']}."]);

        $grant = $this->grants->grant($extension, $organization, $input, $request->user());

        return (new GrantResource($grant))->response()->setStatusCode(201);
    }

    /**
     * PATCH …/extensions/{slug}/access/{grant} — any of `instances` (empty or null: every
     * instance), `max_activations`, `expires_at`, `note`.
     */
    public function update(Request $request, string $slug, string $grant): GrantResource
    {
        $extension = $this->extension($request, $slug);
        $model = $this->grant($extension, $grant);
        $input = $request->validate(self::rules(sometimes: true));

        if ($model->status === Licence::STATUS_REVOKED) {
            throw new ConflictHttpException('This grant is revoked; grant access again instead.');
        }

        return new GrantResource($this->grants->update($model, $input, $request->user()));
    }

    /**
     * DELETE …/extensions/{slug}/access/{grant} — revoke (optional `reason`). Downloads stop on
     * the next request and the grant's seats are released.
     */
    public function destroy(Request $request, string $slug, string $grant): GrantResource
    {
        $extension = $this->extension($request, $slug);
        $model = $this->grant($extension, $grant);
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return new GrantResource($this->grants->revoke($model, $request->user(), $request->input('reason')));
    }

    /**
     * GET /v1/publisher/access/organizations/{organization} — find an organization to grant to:
     * `{id, slug, name, instances}`. `instances` (active ones) is listed for staff and the
     * organization's own members, else null: other publishers add instances by their `inst_` id.
     */
    public function organization(Request $request, string $organization): JsonResponse
    {
        $model = $this->organizationByIdentifier($organization) ?? throw new NotFoundHttpException('Organization not found.');
        $account = $this->account($request);
        $seesInstances = $account->isStaff() || $account->roleIn($model) !== null;

        return response()->json([
            'id' => $model->public_id,
            'slug' => $model->slug,
            'name' => $model->name,
            'instances' => $seesInstances
                ? Instance::query()->where('organization_uuid', $model->uuid)->where('status', Instance::STATUS_ACTIVE)->orderBy('name')->get()
                    ->map(fn (Instance $instance) => ['id' => $instance->public_id, 'name' => $instance->name, 'host' => $instance->host, 'kind' => $instance->kind, 'last_seen_at' => $instance->last_seen_at])
                    ->values()->all()
                : null,
        ]);
    }

    protected static function rules(bool $sometimes = false): array
    {
        $presence = $sometimes ? ['sometimes'] : [];

        return [
            'instances' => [...$presence, 'nullable', 'array', 'max:100'],
            'instances.*' => ['string', 'max:191'],
            'max_activations' => [...$presence, 'nullable', 'integer', 'min:1', 'max:1000'],
            'expires_at' => [...$presence, 'nullable', 'date', 'after:now'],
            'note' => [...$presence, 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The listing: for staff routes any listing, else one the caller owns or administers.
     */
    protected function extension(Request $request, string $slug): Extension
    {
        if ($request->routeIs('v1.admin.*')) {
            return Extension::query()
                ->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))
                ->first() ?? throw new NotFoundHttpException('Extension not found.');
        }

        $extension = $this->findExtension($request, $slug);
        $this->assertManages($request, $extension);

        return $extension;
    }

    protected function grant(Extension $extension, string $id): Licence
    {
        return $this->grants->find($extension, $id) ?? throw new NotFoundHttpException('Grant not found.');
    }

    protected function organizationByIdentifier(string $identifier): ?Organization
    {
        return Organization::query()
            ->where(fn (Builder $q) => $q->where('slug', $identifier)->orWhere('public_id', $identifier))
            ->first();
    }
}
