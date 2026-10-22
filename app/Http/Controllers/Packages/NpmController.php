<?php

namespace App\Http\Controllers\Packages;

use App\Models\Account;
use App\Models\ExtensionPackage;
use App\Services\Packages\NpmPackument;
use App\Services\ReleaseStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The npm subset Fleetbase installs need: packument and tarball. The registry is read-only
 * for npm clients; releases are published with `flb publish` through the releases API.
 */
class NpmController extends PackageController
{
    /** Scoped or unscoped package name as it appears in the (decoded) path. */
    public const NAME_PATTERN = '(?:@[A-Za-z0-9][A-Za-z0-9._~-]*/)?[A-Za-z0-9][A-Za-z0-9._~-]*';

    public const READ_ONLY_MESSAGE = 'This registry does not accept npm publish or other npm write commands. Publish releases with `flb publish`, which submits them through the Fleetbase releases API.';

    public function __construct(protected NpmPackument $packument, protected ReleaseStorage $storage) {}

    /**
     * GET /npm/{name}  (npm sends scoped names as `@scope%2fname`)
     */
    public function packument(Request $request, string $package): Response
    {
        $resolved = $this->resolvePackage($request, ExtensionPackage::KIND_NPM, $package);
        $document = $this->packument->build($resolved);

        abort_if((array) $document['versions'] === [], 404, 'Package not found.');

        return $this->metadataResponse($document, $request, ! $resolved->extension->isPaid());
    }

    /**
     * GET /npm/{name}/-/{basename}-{version}.tgz
     */
    public function tarball(Request $request, string $package, string $file): Response
    {
        $resolved = $this->resolvePackage($request, ExtensionPackage::KIND_NPM, $package);

        $basename = str_contains($resolved->name, '/') ? substr($resolved->name, strpos($resolved->name, '/') + 1) : $resolved->name;
        abort_unless(str_starts_with($file, $basename.'-') && str_ends_with($file, '.tgz'), 404, 'Tarball not found.');
        $version = substr($file, strlen($basename) + 1, -strlen('.tgz'));

        $tarball = $resolved->downloadableRelease($version)?->engineTarball;
        abort_if($tarball === null, 404, 'Tarball not found.');

        return $this->storage->download($tarball, $request, array_merge(
            ['Content-Type' => 'application/octet-stream'],
            $this->archiveCacheHeaders(! $resolved->extension->isPaid()),
        ));
    }

    /**
     * PUT/POST/DELETE/PATCH under /npm: publish, unpublish, dist-tag, deprecate, adduser.
     */
    public function readOnly(): JsonResponse
    {
        return response()->packageError(self::READ_ONLY_MESSAGE, 405, ['Allow' => 'GET, HEAD']);
    }

    /**
     * GET /npm/-/ping  (`npm ping`)
     */
    public function ping(): JsonResponse
    {
        return response()->json(new \stdClass);
    }

    /**
     * GET /npm/-/whoami  (`npm whoami` with a registry token configured)
     */
    public function whoami(Request $request): JsonResponse
    {
        $account = auth('token')->user();
        if (! $account instanceof Account) {
            return response()->packageError('Unauthenticated.', 401);
        }

        return response()->json(['username' => $account->username ?? $account->email]);
    }
}
