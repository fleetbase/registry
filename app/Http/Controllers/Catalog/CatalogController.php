<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Filter\ExtensionFilter;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ExtensionResource;
use App\Http\Resources\ExtensionSummaryResource;
use App\Http\Resources\PublisherResource;
use App\Http\Resources\ReleaseResource;
use App\Models\Category;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Organization;
use App\Models\Release;
use App\Support\CatalogCache;
use App\Support\Compat;
use App\Support\Semver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public, unauthenticated catalog. Every response is cached for 60 seconds, keyed
 * on the full URL, and carries a matching Cache-Control header.
 */
class CatalogController extends Controller
{
    public const CACHE_SECONDS = 60;

    public const MAX_PER_PAGE = 100;

    /**
     * GET /v1/catalog/extensions?q=&category=&pricing=&hosting=&console=&compatible=&sort=&page=&limit=
     *
     * With `console=<version>` each item carries `compatibility`: whether its latest release
     * works with that console and the newest release that does; `compatible=1` keeps only
     * listings with such a release.
     */
    public function extensions(Request $request): JsonResponse
    {
        return $this->cached($request, function () use ($request) {
            $perPage = min(max((int) $request->input('limit', $request->input('per_page', 24)), 1), self::MAX_PER_PAGE);

            $filter = new ExtensionFilter($request);
            $query = $filter->apply($this->listingQuery());
            $filter->applySort($request->input('sort'));

            $paginator = $query->paginate($perPage)->withQueryString();

            $console = $request->input('console');
            if (is_string($console) && Semver::valid($console)) {
                $newest = Compat::newestCompatible($console, $paginator->getCollection()->pluck('uuid'));
                $paginator->getCollection()->each(fn (Extension $extension) => $extension->setAttribute('compatibility', [
                    'console' => $console,
                    'compatible' => $extension->latestRelease !== null && Compat::satisfies($console, data_get($extension->latestRelease->compat, 'console')),
                    'newest_compatible' => $newest[$extension->uuid] ?? null,
                ]));
            }

            return ExtensionSummaryResource::collection($paginator)->response($request)->getData(true);
        });
    }

    /**
     * GET /v1/catalog/extensions/{slug}
     */
    public function extension(Request $request, string $slug): JsonResponse
    {
        return $this->cached($request, function () use ($request, $slug) {
            $extension = $this->listingQuery()
                ->with(['screenshots', 'publishedReleases.engineDist'])
                ->withCount('publishedReleases')
                ->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))
                ->firstOrFail();

            return (new ExtensionResource($extension))->resolve($request);
        });
    }

    /**
     * GET /v1/catalog/extensions/{slug}/releases
     */
    public function releases(Request $request, string $slug): JsonResponse
    {
        return $this->cached($request, function () use ($request, $slug) {
            $extension = Extension::query()->published()
                ->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))
                ->firstOrFail();

            $releases = $extension->releases()
                ->whereIn('status', [Release::STATUS_PUBLISHED, Release::STATUS_YANKED])
                ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->input('channel')))
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->with('engineDist')
                ->get();

            return [
                'extension' => $extension->slug,
                'latest' => $releases->first(fn (Release $r) => $r->isPublished() && $r->channel === Release::CHANNEL_STABLE)?->version
                    ?? $releases->first(fn (Release $r) => $r->isPublished())?->version,
                'channels' => $releases->where('status', Release::STATUS_PUBLISHED)
                    ->groupBy('channel')
                    ->map(fn ($group) => $group->first()->version)
                    ->all(),
                'releases' => ReleaseResource::collection($releases)->resolve($request),
            ];
        });
    }

    /**
     * GET /v1/catalog/publishers/{slug}
     */
    public function publisher(Request $request, string $slug): JsonResponse
    {
        return $this->cached($request, function () use ($request, $slug) {
            $organization = Organization::query()
                ->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))
                ->with(['extensions' => fn ($q) => $this->listingScopes($q->published())->orderByDesc('published_at')])
                ->withCount(['extensions' => fn ($q) => $q->published()])
                ->firstOrFail();

            return (new PublisherResource($organization))->resolve($request);
        });
    }

    /**
     * GET /v1/catalog/categories
     */
    public function categories(Request $request): JsonResponse
    {
        return $this->cached($request, function () use ($request) {
            $categories = Category::query()
                ->withCount(['extensions' => fn ($q) => $q->published()])
                ->orderBy('sort')
                ->orderBy('name')
                ->get();

            return CategoryResource::collection($categories)->resolve($request);
        });
    }

    /**
     * Base query for published listings with everything the summary resource needs.
     */
    protected function listingQuery(): Builder
    {
        return $this->listingScopes(Extension::query()->published());
    }

    protected function listingScopes(Builder|Relation $query)
    {
        return $query
            ->with(['organization', 'category', 'icon', 'price', 'packages', 'latestRelease.engineDist'])
            ->withCount(['installs' => fn ($q) => $q->where('status', Install::STATUS_INSTALLED)]);
    }

    /**
     * Serve from cache for 60 seconds. The closure must return array data. Publishing
     * flushes the cache (`CatalogCache::flush()`).
     */
    protected function cached(Request $request, \Closure $build): JsonResponse
    {
        $data = Cache::remember(CatalogCache::key($request), self::CACHE_SECONDS, $build);

        return response()->json($data)
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS.', s-maxage='.self::CACHE_SECONDS)
            ->header('X-Cache-Ttl', (string) self::CACHE_SECONDS);
    }
}
