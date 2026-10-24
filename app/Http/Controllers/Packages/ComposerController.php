<?php

namespace App\Http\Controllers\Packages;

use App\Models\ExtensionPackage;
use App\Services\Packages\ComposerMetadata;
use App\Services\ReleaseStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Composer v2 repository: add it to a project with
 * `"repositories": [{"type": "composer", "url": "https://registry.fleetbase.io/composer"}]`.
 */
class ComposerController extends PackageController
{
    public const VENDOR_PATTERN = '[a-z0-9][a-z0-9_.-]*';

    public const PACKAGE_PATTERN = '[a-z0-9][a-z0-9_.-]*';

    public function __construct(protected ComposerMetadata $metadata, protected ReleaseStorage $storage) {}

    /**
     * GET /composer/packages.json
     */
    public function packages(Request $request): Response
    {
        return $this->metadataResponse($this->metadata->root(), $request, true);
    }

    /**
     * GET /composer/p2/{vendor}/{package}.json and {package}~dev.json
     */
    public function versions(Request $request, string $vendor, string $package): Response
    {
        $dev = str_ends_with($package, '~dev');
        $name = $vendor.'/'.($dev ? substr($package, 0, -4) : $package);

        $resolved = $this->resolvePackage($request, ExtensionPackage::KIND_COMPOSER, $name);
        $document = $this->metadata->versions($resolved, $dev);

        abort_if(! $dev && $document['packages'][$resolved->name] === [], 404, 'Package not found.');

        return $this->metadataResponse($document, $request, ! $resolved->extension->isPaid());
    }

    /**
     * GET /composer/dist/{vendor}/{package}/{version}.tar
     */
    public function dist(Request $request, string $vendor, string $package, string $version): Response
    {
        $resolved = $this->resolvePackage($request, ExtensionPackage::KIND_COMPOSER, $vendor.'/'.$package);

        $archive = $resolved->downloadableRelease($version)?->apiTarball;
        abort_if($archive === null, 404, 'Archive not found.');

        return $this->storage->download($archive, $request, $this->archiveCacheHeaders(! $resolved->extension->isPaid()));
    }
}
