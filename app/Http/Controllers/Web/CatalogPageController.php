<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Catalog\CatalogController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Compat;
use App\Support\Semver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public pages: discover (search, categories, filters), extension, publisher.
 */
class CatalogPageController extends WebController
{
    public const FILTERS = ['q', 'category', 'pricing', 'hosting', 'sort', 'page', 'compat'];

    /**
     * GET / — search, intent shortcuts, filters and results. With a known console version the
     * catalog keeps only listings that have a release for it, unless `?compat=any`.
     */
    public function home(Request $request): Response
    {
        $filters = $this->only($request, self::FILTERS);
        $console = $this->consoleVersion($request);
        $query = array_diff_key($filters, ['compat' => true]) + ['limit' => 24];
        if ($console) {
            $query += ['console' => $console] + (($filters['compat'] ?? null) === 'any' ? [] : ['compatible' => 1]);
        }
        $results = $this->api($request, '/v1/catalog/extensions', $query, CatalogController::class, 'extensions');

        // Each card says whether a release of it works with the visitor's console.
        $results['data'] = array_map(fn (array $item) => $item + [
            'compatible' => isset($item['compatibility']) ? $item['compatibility']['newest_compatible'] !== null : null,
        ], $results['data'] ?? []);

        return Inertia::render('Discover/Index', [
            'filters' => $filters,
            'results' => $results,
            'categories' => $this->api($request, '/v1/catalog/categories', [], CatalogController::class, 'categories'),
        ]);
    }

    /**
     * GET /extensions/{slug} — overview, screenshots, versions, install.
     */
    public function extension(Request $request, string $slug): Response
    {
        $extension = $this->api($request, "/v1/catalog/extensions/{$slug}", [], CatalogController::class, 'extension', ['slug' => $slug]);
        $releases = $this->api($request, "/v1/catalog/extensions/{$slug}/releases", [], CatalogController::class, 'releases', ['slug' => $slug]);

        return Inertia::render('Extension/Show', [
            'extension' => $extension,
            'releases' => $releases['releases'] ?? [],
            'compatibility' => fn () => $this->compatibility($releases['releases'] ?? [], $request->query('check') ?: $this->consoleVersion($request)),
        ]);
    }

    /**
     * GET /publishers/{slug}
     */
    public function publisher(Request $request, string $slug): Response
    {
        return Inertia::render('Publisher/Show', [
            'publisher' => $this->api($request, "/v1/catalog/publishers/{$slug}", [], CatalogController::class, 'publisher', ['slug' => $slug]),
        ]);
    }

    /**
     * Whether the latest release works with a console version, and if not, the newest release
     * that does. Null when no version is known. `?check=` asks for another version (a partial
     * reload of this prop).
     *
     * @param  list<array<string, mixed>>  $releases  newest first
     */
    protected function compatibility(array $releases, ?string $version): ?array
    {
        if (! $version || ! Semver::valid($version)) {
            return $version ? ['version' => $version, 'valid' => false] : null;
        }

        $published = array_values(array_filter($releases, fn ($release) => ($release['status'] ?? null) === 'published'));
        $latest = $published[0] ?? null;
        $newest = null;
        foreach ($published as $release) {
            if (Compat::satisfies($version, $release['compat']['console'] ?? null)) {
                $newest = $release['version'];
                break;
            }
        }

        return [
            'version' => $version,
            'valid' => true,
            'compatible' => $latest !== null && Compat::satisfies($version, $latest['compat']['console'] ?? null),
            'newest_compatible' => $newest,
        ];
    }

    protected function consoleVersion(Request $request): ?string
    {
        $version = $request->query('console') ?: $request->cookie(HandleInertiaRequests::CONSOLE_COOKIE);

        return is_string($version) && Semver::valid($version) ? $version : null;
    }

    /**
     * POST /console — remember the console version the visitor checks compatibility against.
     */
    public function console(Request $request)
    {
        $request->validate(['version' => ['nullable', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/']]);

        $cookie = $request->filled('version')
            ? cookie()->forever(HandleInertiaRequests::CONSOLE_COOKIE, $request->input('version'), httpOnly: false)
            : cookie()->forget(HandleInertiaRequests::CONSOLE_COOKIE);

        return back()->withCookie($cookie);
    }
}
