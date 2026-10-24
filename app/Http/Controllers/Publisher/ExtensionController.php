<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Requests\Publisher\CreateExtensionRequest;
use App\Http\Requests\Publisher\UpdateExtensionRequest;
use App\Http\Requests\Publisher\UpdatePriceRequest;
use App\Http\Resources\PriceResource;
use App\Http\Resources\PublisherExtensionResource;
use App\Models\Category;
use App\Models\Event;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Review;
use App\Releases\PackageNames;
use App\Support\CatalogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ExtensionController extends PublisherController
{
    public const DETAIL = ['organization', 'category', 'packages', 'price', 'icon', 'screenshots'];

    public function __construct(protected PackageNames $names) {}

    /**
     * GET /v1/publisher/extensions — listings of every organization the caller publishes for.
     */
    public function index(Request $request): JsonResponse
    {
        // Releases give My extensions its live version and the latest release's status.
        $extensions = Extension::query()
            ->with([...self::DETAIL, 'releases' => fn ($q) => self::withDecidedAt($q)])
            ->whereIn('organization_uuid', $this->publishableOrganizations($request))
            ->orderBy('name')
            ->get();

        return response()->json(PublisherExtensionResource::collection($extensions)->resolve($request));
    }

    /**
     * GET /v1/publisher/extensions/{slug}
     */
    public function show(Request $request, string $slug): PublisherExtensionResource
    {
        $extension = $this->findExtension($request, $slug, self::DETAIL);
        $extension->load(['releases' => fn ($q) => self::withDecidedAt($q)->orderByDesc('created_at')->orderByDesc('id')]);

        return new PublisherExtensionResource($extension);
    }

    /**
     * POST /v1/publisher/extensions — create a listing, idempotent on package names: when the
     * names already belong to one of the caller's listings, that listing is returned (200)
     * and any package kind it lacks is added. Names owned by another publisher answer 409.
     */
    public function store(CreateExtensionRequest $request): JsonResponse
    {
        $account = $this->account($request);
        $packages = $request->packages();

        $existing = $this->listingOwning($packages);
        if ($existing !== null) {
            if (! $this->canPublishFor($request, $existing->organization_uuid)) {
                throw new ConflictHttpException('These package names are registered to another publisher\'s extension.');
            }

            if ($request->filled('organization')) {
                $organization = $this->targetOrganization($request, $request->input('organization'));
                if ($organization->uuid !== $existing->organization_uuid) {
                    throw new ConflictHttpException("These package names belong to {$existing->slug}, which another of your organizations owns.");
                }
            }

            $this->attachPackages($request, $existing, $existing->organization()->firstOrFail(), $packages);

            return (new PublisherExtensionResource($existing->load(self::DETAIL)))->response()->setStatusCode(200);
        }

        $organization = $this->targetOrganization($request, $request->input('organization'));
        $slug = $request->input('slug') ?? Str::slug($request->input('name'));

        $bySlug = Extension::withTrashed()->where('slug', $slug)->first();
        if ($bySlug !== null) {
            if ($bySlug->trashed() || $bySlug->organization_uuid !== $organization->uuid) {
                throw ValidationException::withMessages(['slug' => "The slug {$slug} is taken."]);
            }

            // A listing of this organization with the slug and none of these names: claim them.
            $this->attachPackages($request, $bySlug, $organization, $packages);

            return (new PublisherExtensionResource($bySlug->load(self::DETAIL)))->response()->setStatusCode(200);
        }

        $this->assertClaimable($request, $organization, $packages);

        $extension = DB::transaction(function () use ($request, $account, $organization, $slug, $packages) {
            $extension = Extension::create(array_merge($request->listingAttributes(), [
                'organization_uuid' => $organization->uuid,
                'created_by_account_uuid' => $account->uuid,
                'category_uuid' => $this->categoryUuid($request->input('category')),
                'slug' => $slug,
                'status' => Extension::STATUS_DRAFT,
                'hosting' => $request->input('hosting', Extension::HOSTING_BOTH),
            ]));
            $extension->price()->create(['model' => Price::MODEL_FREE, 'amount' => 0, 'currency' => 'USD']);
            foreach ($packages as $kind => $name) {
                $extension->packages()->create(['kind' => $kind, 'name' => $name]);
            }

            return $extension;
        });

        Event::record('extension.created', $extension, $account, ['packages' => $packages]);

        return (new PublisherExtensionResource($extension->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    /**
     * GET /v1/publisher/packages/availability?organization=&npm=&composer= — whether the
     * organization can claim each name for a new listing, by the rules `store` applies:
     * `{npm: {name, available, reason}, composer: {…}}` for the names given.
     */
    public function availability(Request $request): JsonResponse
    {
        $request->merge(array_map(PackageNames::normalize(...), $request->only(ExtensionPackage::KINDS)));
        $request->validate([
            'organization' => ['nullable', 'string', 'max:191'],
            'npm' => ['nullable', 'required_without:composer', 'string', 'max:1000'],
            'composer' => ['nullable', 'required_without:npm', 'string', 'max:1000'],
        ]);

        $organization = $this->targetOrganization($request, $request->query('organization'));
        $publishable = $this->publishableOrganizations($request);
        $result = [];

        foreach (ExtensionPackage::KINDS as $kind) {
            $name = $request->input($kind);
            if (! is_string($name) || $name === '') {
                continue;
            }

            $reason = $this->names->problem($organization, $kind, $name, $publishable);
            $result[$kind] = ['name' => $name, 'available' => $reason === null, 'reason' => $reason];
        }

        return response()->json($result);
    }

    /**
     * PATCH /v1/publisher/extensions/{slug} — listing copy, links, category, tags, hosting.
     * Listing edits never need review.
     */
    public function update(UpdateExtensionRequest $request, string $slug): PublisherExtensionResource
    {
        $extension = $this->findExtension($request, $slug);
        $attributes = $request->listingAttributes();
        if ($request->has('category')) {
            $attributes['category_uuid'] = $this->categoryUuid($request->input('category'));
        }

        $extension->fill($attributes)->save();
        Event::record('extension.updated', $extension, $this->account($request), ['fields' => array_keys($attributes)]);

        if ($extension->status === Extension::STATUS_PUBLISHED) {
            CatalogCache::flush();
        }

        return new PublisherExtensionResource($extension->load(self::DETAIL));
    }

    /**
     * PUT /v1/publisher/extensions/{slug}/price — `{model, amount, currency}`. Paid pricing
     * needs the organization's Stripe Connect account. The price takes effect at once; under
     * D6 a price that differs from the last release's snapshot sends the next release to
     * human review.
     */
    public function price(UpdatePriceRequest $request, string $slug): PublisherExtensionResource
    {
        $extension = $this->findExtension($request, $slug, ['organization', 'price']);
        $model = $request->input('model');

        if ($model !== Price::MODEL_FREE && blank($extension->organization?->stripe_connect_id)) {
            throw new UnprocessableEntityHttpException('Paid pricing opens when Stripe Connect payouts ship (Phase 4).');
        }

        $attributes = $model === Price::MODEL_FREE
            ? ['model' => Price::MODEL_FREE, 'amount' => 0, 'currency' => $request->input('currency') ?? $extension->price?->currency ?? 'USD', 'on_sale' => false, 'sale_amount' => null]
            : ['model' => $model, 'amount' => (int) $request->input('amount'), 'currency' => $request->input('currency')];

        $before = $extension->price ? (new PriceResource($extension->price))->resolve($request) : null;
        $price = $extension->price ?? $extension->price()->make();
        $price->fill($attributes)->save();

        Event::record('extension.price_changed', $extension, $this->account($request), ['from' => $before, 'to' => $attributes]);

        if ($extension->status === Extension::STATUS_PUBLISHED) {
            CatalogCache::flush();
        }

        return new PublisherExtensionResource($extension->fresh(self::DETAIL));
    }

    /**
     * Each release's `decided_at`: when it was last approved or rejected (one subquery, so
     * the release summaries need not load every review row).
     */
    public static function withDecidedAt($query)
    {
        return $query->withMax(['reviews as decided_at' => fn ($q) => $q->whereIn('action', [Review::ACTION_APPROVE, Review::ACTION_REJECT])], 'created_at');
    }

    /**
     * The one listing that owns any of the names, or null. Names spread over two listings
     * answer 409.
     */
    protected function listingOwning(array $packages): ?Extension
    {
        $owners = ExtensionPackage::query()
            ->where(function (Builder $q) use ($packages) {
                foreach ($packages as $kind => $name) {
                    $q->orWhere(fn (Builder $q) => $q->where('kind', $kind)->where('name', $name));
                }
            })
            ->pluck('extension_uuid')
            ->unique();

        if ($owners->count() > 1) {
            throw new ConflictHttpException('These package names belong to different extensions.');
        }

        if ($owners->isEmpty()) {
            return null;
        }

        $extension = Extension::withTrashed()->find($owners->first());
        if ($extension === null || $extension->trashed()) {
            throw new ConflictHttpException('These package names belong to a deleted extension; contact Fleetbase staff.');
        }

        return $extension;
    }

    /**
     * Add package kinds the listing does not have yet. A different name for a kind the
     * listing already has is a rename, which this endpoint does not do.
     */
    protected function attachPackages(Request $request, Extension $extension, Organization $organization, array $packages): void
    {
        $current = $extension->packages()->pluck('name', 'kind');
        $new = [];

        foreach ($packages as $kind => $name) {
            if (! $current->has($kind)) {
                $new[$kind] = $name;
            } elseif ($current->get($kind) !== $name) {
                throw new ConflictHttpException("{$extension->slug} already has the {$kind} package {$current->get($kind)}; package names cannot be changed here.");
            }
        }

        $this->assertClaimable($request, $organization, $new);
        foreach ($new as $kind => $name) {
            $extension->packages()->create(['kind' => $kind, 'name' => $name]);
        }
    }

    /**
     * Refuse names the organization may not claim, with the reasons the availability check
     * gives (`PackageNames::problem`).
     */
    protected function assertClaimable(Request $request, Organization $organization, array $packages): void
    {
        $publishable = $this->publishableOrganizations($request);
        $errors = [];
        foreach ($packages as $kind => $name) {
            if ($problem = $this->names->problem($organization, $kind, $name, $publishable)) {
                $errors['packages.'.$kind] = [$problem];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function categoryUuid(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $category = Category::query()
            ->where(fn (Builder $q) => $q->where('slug', $identifier)->orWhere('public_id', $identifier)->orWhere('uuid', $identifier))
            ->first();

        if ($category === null) {
            throw ValidationException::withMessages(['category' => "Unknown category {$identifier}."]);
        }

        return $category->uuid;
    }
}
