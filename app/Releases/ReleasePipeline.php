<?php

namespace App\Releases;

use App\Jobs\RunReleaseChecks;
use App\Mail\ReleaseDecisionMail;
use App\Models\Account;
use App\Models\Event;
use App\Models\Extension;
use App\Models\File;
use App\Models\Release;
use App\Models\ReleaseCheck;
use App\Models\Review;
use App\Releases\Archives\ReleaseArchives;
use App\Releases\Checks\ArchiveCheck;
use App\Releases\Checks\Check;
use App\Releases\Checks\CheckContext;
use App\Releases\Checks\CheckResult;
use App\Releases\Checks\CompatCheck;
use App\Releases\Checks\DistCheck;
use App\Releases\Checks\ManifestCheck;
use App\Releases\Checks\PackageNamesCheck;
use App\Releases\Checks\SecretsScanCheck;
use App\Releases\Checks\SizeCheck;
use App\Releases\Checks\VersionCheck;
use App\Services\ReleaseStorage;
use App\Support\CatalogCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The releases pipeline (Workstream E): upload, automated checks, submission, the D6
 * review policy, staff review, publication and yanking. Status changes go through
 * `ReleaseStateMachine`, which writes the `reviews` timeline.
 */
class ReleasePipeline
{
    /** Check classes in the order they run. */
    public const CHECKS = [
        ManifestCheck::class, CompatCheck::class, PackageNamesCheck::class, VersionCheck::class,
        SizeCheck::class, ArchiveCheck::class, SecretsScanCheck::class, DistCheck::class,
    ];

    public const FILE_TYPES = [
        'engine' => File::TYPE_ENGINE_TARBALL,
        'api' => File::TYPE_API_TARBALL,
        'dist' => File::TYPE_ENGINE_DIST,
    ];

    public const FILE_COLUMNS = [
        'engine' => 'engine_tarball_file_uuid',
        'api' => 'api_tarball_file_uuid',
        'dist' => 'engine_dist_file_uuid',
    ];

    public function __construct(
        protected ReleaseStateMachine $machine,
        protected ReleaseStorage $storage,
        protected ReviewPolicy $policy,
        protected ListingReadiness $readiness,
    ) {}

    // ---------------------------------------------------------------------
    // Upload and checks
    // ---------------------------------------------------------------------

    /**
     * Store a new release and queue its checks. Uploading a version whose release was never
     * submitted, failed its checks or was rejected replaces that release.
     *
     * @param  array<string, UploadedFile>  $uploads  `engine`, `api`, `dist`
     */
    public function upload(Extension $extension, Account $account, array $manifest, string $channel, ?string $notes, array $uploads, bool $submit = false): Release
    {
        $version = (string) $manifest['version'];

        $existing = Release::withTrashed()->where('extension_uuid', $extension->uuid)->where('version', $version)->first();
        if ($existing !== null) {
            if (! $existing->trashed() && ! $existing->isReplaceable()) {
                throw new ConflictHttpException("Version {$version} is already ".str_replace('_', ' ', $existing->status).'; release a new version.');
            }
            $this->discard($existing, $account);
        }

        try {
            $release = Release::create([
                'extension_uuid' => $extension->uuid,
                'created_by_account_uuid' => $account->uuid,
                'version' => $version,
                'channel' => $channel,
                'status' => Release::STATUS_UPLOADED,
                'manifest' => $manifest,
                'compat' => array_filter([
                    'console' => is_string($manifest['compat']['console'] ?? null) ? $manifest['compat']['console'] : null,
                    'api' => is_string($manifest['compat']['api'] ?? null) ? $manifest['compat']['api'] : null,
                ]) ?: null,
                'notes' => $notes,
                'meta' => ['submission' => ['requested' => $submit, 'requested_by' => $submit ? $account->uuid : null, 'errors' => []]],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException("Version {$version} is being uploaded by another request.");
        }

        $stored = [];
        try {
            foreach ($uploads as $label => $upload) {
                $stored[$label] = $this->storage->store($upload->getRealPath(), self::FILE_TYPES[$label], $release, [
                    'name' => $this->filename($label, $upload, $extension, $version),
                    'original_filename' => $upload->getClientOriginalName(),
                    'uploader_account_uuid' => $account->uuid,
                ]);
            }
        } catch (\Throwable $e) {
            foreach ($stored as $file) {
                $this->storage->delete($file);
            }
            $release->forceDelete();

            throw $e;
        }

        $release->forceFill(array_merge(
            collect($stored)->mapWithKeys(fn (File $file, string $label) => [self::FILE_COLUMNS[$label] => $file->uuid])->all(),
            [
                'size_bytes' => collect($stored)->sum('size'),
                // Bundle digest: sha256 over the sorted `label:sha256` lines of its files.
                'checksum' => hash('sha256', collect($stored)->map(fn (File $file, string $label) => $label.':'.$file->checksum)->sort()->implode("\n")),
            ],
        ))->save();

        $this->machine->recordUpload($release, $account);
        Event::record('release.uploaded', $release, $account, ['version' => $version, 'extension' => $extension->public_id]);

        RunReleaseChecks::dispatch($release)->afterCommit();

        return $release->refresh();
    }

    /**
     * Run every check, record `release_checks`, then move to `failed_checks` or back to
     * `uploaded` and, when submission was requested at upload, submit.
     */
    public function runChecks(Release $release): Release
    {
        $release = $this->machine->apply($release, Review::ACTION_CHECK);
        $release->checks()->delete();
        $release->load(['extension.packages', 'extension.price', 'engineTarball', 'apiTarball', 'engineDist']);

        $checks = array_map(fn (string $class) => app($class), self::CHECKS);
        $mirror = collect($checks)->contains(fn (Check $check) => $check instanceof SecretsScanCheck && $check->wantsGitleaks());

        $archives = ReleaseArchives::open($release, $mirror);
        $context = new CheckContext($release, $release->extension, $release->manifest ?? [], $archives);
        $failed = [];

        try {
            foreach ($checks as $check) {
                try {
                    $result = $check->run($context);
                } catch (\Throwable $e) {
                    report($e);
                    $result = CheckResult::fail('The check could not run.', ["The {$check->name()} check hit an internal error; Fleetbase staff have been notified."]);
                }

                ReleaseCheck::updateOrCreate(
                    ['release_uuid' => $release->uuid, 'check' => $check->name()],
                    ['passed' => $result->passed, 'output' => $result->toOutput()],
                );

                if (! $result->passed) {
                    $failed[] = $check->name();
                }
            }
        } finally {
            $archives->cleanup();
        }

        $release->mergeMeta(array_merge($context->meta, ['checks_completed_at' => now()->toIso8601String()]))->save();
        $release->unsetRelation('checks');

        if ($failed !== []) {
            $release = $this->machine->apply($release, Review::ACTION_FAIL_CHECKS, reason: 'Failed checks: '.implode(', ', $failed).'.');
            $this->notify($release, ReleaseDecisionMail::FAILED_CHECKS);

            return $release;
        }

        $release = $this->machine->apply($release, Review::ACTION_PASS_CHECKS);

        if ($release->metaValue('submission.requested')) {
            $actor = Account::find($release->metaValue('submission.requested_by'));
            try {
                $release = $this->submit($release, $actor);
            } catch (ReleaseNotReady $e) {
                $release->mergeMeta(['submission.requested' => false, 'submission.errors' => $e->validator->errors()->all()])->save();
            }
        }

        return $release;
    }

    /**
     * Mark a release whose checks could not run at all (the job failed).
     */
    public function failChecks(Release $release, string $reason): void
    {
        if (in_array($release->status, [Release::STATUS_UPLOADED, Release::STATUS_CHECKING], true)) {
            $this->machine->apply($release, Review::ACTION_FAIL_CHECKS, reason: $reason);
        }
    }

    // ---------------------------------------------------------------------
    // Publisher actions
    // ---------------------------------------------------------------------

    public function checksPending(Release $release): bool
    {
        return $release->status === Release::STATUS_CHECKING
            || ($release->status === Release::STATUS_UPLOADED && $release->metaValue('checks_completed_at') === null);
    }

    /**
     * Submit for review. While checks are still running the request is remembered and
     * acted on when they finish. Under D6 a release that needs no human review is approved
     * and published here.
     *
     * @throws ReleaseNotReady|InvalidReleaseTransition
     */
    public function submit(Release $release, ?Account $actor): Release
    {
        $release->loadMissing('extension');

        if ($this->checksPending($release)) {
            $release->mergeMeta([
                'submission.requested' => true,
                'submission.requested_by' => $actor?->uuid,
                'submission.errors' => [],
            ])->save();

            return $release;
        }

        ReleaseStateMachine::assertCan($release, Review::ACTION_SUBMIT);

        $reasons = [];
        if (! $release->checksPassed()) {
            $reasons[] = 'The release has failing checks.';
        }
        if ($release->extension->status === Extension::STATUS_SUSPENDED) {
            $reasons[] = 'The listing is suspended.';
        }
        array_push($reasons, ...$this->readiness->errors($release->extension));
        if ($reasons !== []) {
            throw ReleaseNotReady::because($reasons);
        }

        $policy = $this->policy->evaluate($release);
        $meta = $release->mergeMeta([
            'submission' => ['requested' => false, 'requested_by' => $actor?->uuid, 'errors' => [], 'submitted_at' => now()->toIso8601String()],
            'review_policy' => $policy,
        ])->meta;

        $release = $this->machine->apply($release, Review::ACTION_SUBMIT, $actor, Review::ACTOR_PUBLISHER, attributes: ['meta' => $meta]);
        Event::record('release.submitted', $release, $actor, ['review_required' => $policy['required'], 'reasons' => array_keys($policy['reasons'])]);

        if (! $policy['required']) {
            $release = $this->machine->apply($release, Review::ACTION_APPROVE, reason: 'Approved automatically: checks passed and nothing that review policy D6 covers changed.');
            $release = $this->publish($release, null);
        }

        return $release;
    }

    /**
     * Take a release out of review (back to `uploaded`), or cancel a submission still
     * waiting on checks.
     */
    public function withdraw(Release $release, Account $actor, ?string $reason = null): Release
    {
        if ($this->checksPending($release) && $release->metaValue('submission.requested')) {
            $release->mergeMeta(['submission.requested' => false])->save();

            return $release;
        }

        $release = $this->machine->apply($release, Review::ACTION_WITHDRAW, $actor, Review::ACTOR_PUBLISHER, $reason);
        Event::record('release.withdrawn', $release, $actor);

        return $release;
    }

    /**
     * Hide a published release from metadata and new installs. Its archives stay
     * downloadable for lockfiles and instances that already have it.
     */
    public function yank(Release $release, Account $actor, ?string $reason = null): Release
    {
        $release = $this->machine->apply($release, Review::ACTION_YANK, $actor, Review::ACTOR_PUBLISHER, $reason, attributes: ['yanked_at' => now()]);
        Event::record('release.yanked', $release, $actor, ['reason' => $reason]);
        CatalogCache::flush();

        return $release;
    }

    // ---------------------------------------------------------------------
    // Staff actions
    // ---------------------------------------------------------------------

    public function claim(Release $release, Account $staff): Release
    {
        return $this->machine->apply($release, Review::ACTION_CLAIM, $staff, Review::ACTOR_STAFF);
    }

    /**
     * Approve, and publish straight away unless `$publish` is false (then a staff publish
     * follows later).
     */
    public function approve(Release $release, Account $staff, ?string $reason = null, ?array $items = null, bool $publish = true): Release
    {
        ReleaseStateMachine::assertCan($release, Review::ACTION_APPROVE);
        if (! $release->checksPassed()) {
            throw ReleaseNotReady::because(['The release has failing checks.']);
        }

        $release = $this->machine->apply($release, Review::ACTION_APPROVE, $staff, Review::ACTOR_STAFF, $reason, $items);
        Event::record('release.approved', $release, $staff);

        return $publish ? $this->publish($release, $staff) : $release;
    }

    public function reject(Release $release, Account $staff, string $reason, array $items): Release
    {
        $release = $this->machine->apply($release, Review::ACTION_REJECT, $staff, Review::ACTOR_STAFF, $reason, $items);
        Event::record('release.rejected', $release, $staff, ['reason' => $reason]);
        $this->notify($release, ReleaseDecisionMail::REJECTED);

        return $release;
    }

    /**
     * Publish an approved release: sets `published_at`, snapshots the price for the next
     * D6 comparison, lists the extension if this is its first release, and flushes the
     * catalog cache. npm dist-tags and Composer metadata are built from published releases,
     * so they follow immediately.
     */
    public function publish(Release $release, ?Account $staff): Release
    {
        $release->loadMissing('extension.price');
        ReleaseStateMachine::assertCan($release, Review::ACTION_PUBLISH);

        $extension = $release->extension;
        $reasons = [];
        if (! $release->isPublishable()) {
            $reasons[] = 'Only an approved release whose checks all passed can be published.';
        }
        if ($extension->status === Extension::STATUS_SUSPENDED) {
            $reasons[] = 'The listing is suspended.';
        }
        if ($reasons !== []) {
            throw ReleaseNotReady::because($reasons);
        }

        $now = now();
        $meta = $release->mergeMeta(['price' => ReviewPolicy::priceSnapshot($extension->price)])->meta;

        $release = DB::transaction(function () use ($release, $staff, $extension, $now, $meta) {
            $release = $this->machine->apply($release, Review::ACTION_PUBLISH, $staff, Review::ACTOR_STAFF, attributes: ['published_at' => $now, 'meta' => $meta]);

            if (in_array($extension->status, [Extension::STATUS_DRAFT, Extension::STATUS_IN_REVIEW, Extension::STATUS_APPROVED, Extension::STATUS_REJECTED], true)) {
                $extension->forceFill(['status' => Extension::STATUS_PUBLISHED, 'published_at' => $extension->published_at ?? $now])->save();
            }

            return $release;
        });

        Event::record('release.published', $release, $staff, ['version' => $release->version, 'extension' => $extension->public_id]);
        CatalogCache::flush();
        $this->notify($release, ReleaseDecisionMail::PUBLISHED);

        return $release;
    }

    /**
     * Take a listing out of the catalog and the package endpoints. Releases are untouched.
     */
    public function suspend(Extension $extension, Account $staff, string $reason): Extension
    {
        if ($extension->status !== Extension::STATUS_SUSPENDED) {
            $meta = $extension->meta ?? [];
            $meta['suspension'] = [
                'reason' => $reason,
                'by' => $staff->public_id,
                'at' => now()->toIso8601String(),
                'previous_status' => $extension->status,
            ];
            $extension->forceFill(['status' => Extension::STATUS_SUSPENDED, 'meta' => $meta])->save();

            Event::record('extension.suspended', $extension, $staff, ['reason' => $reason]);
            CatalogCache::flush();
        }

        return $extension;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Delete a replaceable release with its files, checks and timeline. The timeline is
     * kept in the audit log.
     */
    protected function discard(Release $release, Account $actor): void
    {
        Event::record('release.replaced', $release, $actor, [
            'version' => $release->version,
            'status' => $release->status,
            'reviews' => $release->reviews()->get(['action', 'from_status', 'to_status', 'reason', 'items', 'created_at'])->toArray(),
        ]);

        foreach (Release::withTrashed()->whereKey($release->getKey())->first()?->archives() ?? [] as $file) {
            $this->storage->delete($file);
        }
        $release->checks()->delete();
        $release->reviews()->delete();
        $release->forceDelete();
    }

    protected function filename(string $label, UploadedFile $upload, Extension $extension, string $version): string
    {
        $base = $extension->slug.'-'.$version;

        return match ($label) {
            'engine' => $base.'-engine.tgz',
            'api' => $base.'-api'.(str_ends_with(strtolower($upload->getClientOriginalName()), 'gz') ? '.tar.gz' : '.tar'),
            default => $base.'-dist'.(str_ends_with(strtolower($upload->getClientOriginalName()), 'gz') ? '.tar.gz' : '.tar'),
        };
    }

    protected function notify(Release $release, string $decision): void
    {
        try {
            $release->loadMissing(['createdBy', 'extension']);
            if ($release->createdBy?->email) {
                Mail::to($release->createdBy)->queue(new ReleaseDecisionMail($release, $decision));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
