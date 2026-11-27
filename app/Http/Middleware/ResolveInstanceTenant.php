<?php

namespace App\Http\Middleware;

use App\Models\Instance;
use App\Support\CloudPlatform;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tenant` — on instance routes, lets the cloud platform instance act for the cloud company
 * named in `X-Registry-Tenant` (its uuid; `X-Registry-Tenant-Name` names an organization created
 * for a company seen for the first time). Licences, installs and checkouts then belong to that
 * company's organization. Other instances never act for anyone: the header is ignored.
 */
class ResolveInstanceTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $instance = $request->user();
        $company = trim((string) $request->header(CloudPlatform::TENANT_HEADER, ''));

        if ($instance instanceof Instance && $instance->isPlatform() && $company !== '') {
            $tenant = CloudPlatform::tenant($company, rawurldecode((string) $request->header(CloudPlatform::TENANT_NAME_HEADER, '')));
            if ($tenant === null) {
                return response()->error('X-Registry-Tenant must be the cloud company uuid.', 422);
            }

            $instance->actAs($tenant);
        }

        return $next($request);
    }
}
