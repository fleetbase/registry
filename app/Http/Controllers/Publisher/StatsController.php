<?php

namespace App\Http\Controllers\Publisher;

use App\Analytics\ExtensionStats;
use App\Analytics\StatsCsv;
use App\Analytics\StatsRange;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A listing's analytics for its publisher: installs, updates, uninstalls, active instances by
 * version, purchases and revenue over a date range, against the period before it.
 */
class StatsController extends PublisherController
{
    /**
     * GET /v1/publisher/extensions/{slug}/stats?from=&to=&group=day|week|month
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $extension = $this->findExtension($request, $slug);
        $range = StatsRange::fromRequest($request);

        return response()->json(['extension' => ExtensionStats::extensionReference($extension)] + $this->report($extension, $range));
    }

    /**
     * GET /v1/publisher/extensions/{slug}/stats.csv?from=&to=&group= — the series as CSV.
     */
    public function export(Request $request, string $slug): StreamedResponse
    {
        $extension = $this->findExtension($request, $slug);
        $range = StatsRange::fromRequest($request);

        return StatsCsv::download($this->report($extension, $range), StatsCsv::filename($extension->slug, $range));
    }

    protected function report(Extension $extension, StatsRange $range): array
    {
        return (new ExtensionStats(collect([$extension])))->cachedReport($range, versions: true);
    }
}
