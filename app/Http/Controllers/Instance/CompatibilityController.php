<?php

namespace App\Http\Controllers\Instance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instance\CompatibilityRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

/**
 * Pre-upgrade compatibility: would the extensions installed on an instance work on a target
 * core version? See `CompatibilityReport` for the rules and the response.
 */
class CompatibilityController extends Controller
{
    /**
     * GET /v1/instances/me/compatibility — the calling instance (flbi_ token).
     */
    public function me(CompatibilityRequest $request): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();

        return response()->json($request->report()->for($instance, $request));
    }

    /**
     * GET /v1/admin/instances/{instance}/compatibility — any instance by its `inst_` id, for staff
     * (personal token with the `review` scope in an `is_staff` organization). Staff see private
     * extensions.
     */
    public function show(CompatibilityRequest $request, string $instance): JsonResponse
    {
        $found = Instance::query()->where('public_id', $instance)->first();
        if ($found === null) {
            return response()->error('Instance not found.', 404);
        }

        return response()->json($request->report()->for($found, $request, staff: true));
    }
}
