<?php

namespace App\Jobs;

use App\Models\Release;
use App\Releases\ReleasePipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a release's automated checks (`ReleasePipeline::CHECKS`) and records each in
 * `release_checks`. One job per release: the archives are fetched and inspected once and
 * every check reads that inspection.
 */
class RunReleaseChecks implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /** A release replaced before its checks ran needs no checks. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Release $release)
    {
        $this->onQueue(config('registry.releases.checks_queue'));
    }

    public function handle(ReleasePipeline $pipeline): void
    {
        $release = Release::query()->whereKey($this->release->getKey())->first();

        if ($release === null || ! $pipeline->checksPending($release)) {
            return;
        }

        $pipeline->runChecks($release);
    }

    public function failed(?\Throwable $exception): void
    {
        $release = Release::query()->whereKey($this->release->getKey())->first();

        if ($release !== null) {
            app(ReleasePipeline::class)->failChecks($release, 'The checks could not run. Upload the release again; if this keeps happening, contact Fleetbase staff.');
        }
    }
}
