<?php

namespace App\Http\Controllers\Instance;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Release;
use App\Services\LicenceGate;
use App\Services\ReleaseStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReleaseDistController extends Controller
{
    public function __construct(protected ReleaseStorage $storage, protected LicenceGate $gate) {}

    /**
     * GET /v1/instances/me/releases/{release}/dist — the release's prebuilt engine dist
     * (`fleetbase-build-engine-release` output, uploaded with `flb publish`), which a console
     * installs as a runtime engine without a rebuild. `dist.url` on catalog and update releases
     * points here, next to the size, sha256 and builtAgainst versions to verify it with.
     *
     * Free extensions: any active instance. Paid: the instance's organization must own the
     * extension or hold an active licence for it (403 otherwise, re-checked on every request so
     * a revoked licence takes effect at once). Yanked releases stay downloadable for the
     * instances that already run them; suspended listings and releases without a dist are 404.
     */
    public function show(Request $request, string $release): Response
    {
        /** @var Instance $instance */
        $instance = $request->user();

        $model = Release::query()
            ->released()
            ->where(fn ($q) => $q->where('public_id', $release)->orWhere('uuid', $release))
            ->whereHas('extension', fn ($q) => $q->where('status', '!=', Extension::STATUS_SUSPENDED))
            ->with(['engineDist', 'extension.price'])
            ->first();

        if ($model === null) {
            return response()->error('Release not found.', 404);
        }
        if ($model->engineDist === null) {
            return response()->error('This release has no prebuilt dist.', 404);
        }

        $paid = $model->extension->isPaid();
        if ($paid && ! $this->gate->allows($instance, $model->extension)) {
            return response()->error("Your organization does not hold an active licence for {$model->extension->name}.", 403)
                ->header('Cache-Control', 'private, no-store');
        }

        // Instance-authenticated either way, so never in a shared cache. A free dist is immutable
        // per release; a paid one is revalidated so the licence check runs again.
        return $this->storage->download($model->engineDist, $request, $paid
            ? ['Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization']
            : ['Cache-Control' => 'private, max-age=31536000, immutable']);
    }
}
