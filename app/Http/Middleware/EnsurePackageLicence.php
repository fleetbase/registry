<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Packages\PackageController;
use App\Models\ExtensionPackage;
use App\Services\LicenceGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `licence:npm` / `licence:composer` on package metadata and archive routes.
 *
 * Free packages pass straight through. Paid packages need a bearer token whose organization
 * holds an active licence (see LicenceGate): 401 without a usable token, 403 with one.
 * Unknown packages also pass through so the controller answers 404. The resolved package
 * is handed to the controller as a request attribute.
 */
class EnsurePackageLicence
{
    public function __construct(protected LicenceGate $gate) {}

    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $name = $this->packageName($request, $kind);
        $package = $name !== null ? ExtensionPackage::resolve($kind, $name) : null;

        if ($package === null) {
            return $next($request);
        }

        $request->attributes->set(PackageController::PACKAGE_ATTRIBUTE, $package);

        if (! $package->extension->isPaid()) {
            return $next($request);
        }

        $decision = $this->gate->check($request, $package->extension);
        if ($decision['status'] === LicenceGate::STATUS_ALLOWED) {
            return $next($request);
        }

        $headers = ['Cache-Control' => 'private, no-store'];
        if ($decision['status'] === LicenceGate::STATUS_UNAUTHENTICATED) {
            $headers['WWW-Authenticate'] = 'Bearer realm="Fleetbase Registry"';
        }

        return response()->packageError($decision['message'], $decision['status'], $headers);
    }

    protected function packageName(Request $request, string $kind): ?string
    {
        $route = $request->route();

        return match ($kind) {
            ExtensionPackage::KIND_NPM => $route?->parameter('package'),
            ExtensionPackage::KIND_COMPOSER => $route?->parameter('vendor') && $route?->parameter('package')
                ? $route->parameter('vendor').'/'.preg_replace('/~dev$/', '', $route->parameter('package'))
                : null,
            default => throw new \InvalidArgumentException("Unknown package kind [{$kind}]."),
        };
    }
}
