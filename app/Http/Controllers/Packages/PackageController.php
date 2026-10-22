<?php

namespace App\Http\Controllers\Packages;

use App\Http\Controllers\Controller;
use App\Models\ExtensionPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Shared plumbing for the npm and Composer endpoints: package lookup, JSON metadata with a
 * content ETag (304 on `If-None-Match`), and cache headers that keep paid packages out of
 * shared caches.
 */
abstract class PackageController extends Controller
{
    /** Request attribute holding the package once a middleware has resolved it. */
    public const PACKAGE_ATTRIBUTE = 'extension_package';

    protected function resolvePackage(Request $request, string $kind, string $name): ExtensionPackage
    {
        $package = $request->attributes->get(self::PACKAGE_ATTRIBUTE) ?? ExtensionPackage::resolve($kind, $name);

        abort_if($package === null || $package->kind !== $kind, 404, 'Package not found.');

        return $package;
    }

    protected function metadataResponse(array $document, Request $request, bool $public): Response
    {
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = new Response($json, 200, ['Content-Type' => 'application/json']);
        $response->setEtag(sha1($json));
        $this->cacheFor($response, $public, config('registry.package_metadata_cache_seconds', 60));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Free packages may sit in shared caches; paid ones are private and revalidated every
     * time so a revoked licence takes effect on the next request.
     */
    protected function cacheFor(SymfonyResponse $response, bool $public, int $maxAge): SymfonyResponse
    {
        if ($public) {
            $response->setPublic();
            $response->setMaxAge($maxAge);
        } else {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-cache');
            $response->setVary('Authorization', false);
        }

        return $response;
    }

    protected function archiveCacheHeaders(bool $public): array
    {
        return $public
            ? ['Cache-Control' => 'public, max-age=31536000, immutable']
            : ['Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization'];
    }
}
