<?php

namespace App\Releases;

use App\Models\Account;
use App\Models\Release;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * The release status machine. Every transition writes one `reviews` row (action, from and
 * to status, actor, reason, items) in the same transaction as the status change, with the
 * release row locked so two requests cannot both move it.
 *
 *   uploaded ──check──▶ checking ──pass_checks──▶ uploaded (checks passed, not submitted)
 *                          └──fail_checks──▶ failed_checks
 *   uploaded | rejected ──submit──▶ submitted ──claim──▶ in_review
 *   submitted | in_review ──approve──▶ approved ──publish──▶ published ──yank──▶ yanked
 *   submitted | in_review | approved ──reject──▶ rejected
 *   submitted | in_review | approved ──withdraw──▶ uploaded
 *
 * Guards that depend on more than the status (checks passed, listing ready, D6) live in
 * `ReleasePipeline`.
 */
class ReleaseStateMachine
{
    /** action => [allowed from statuses, to status] */
    public const TRANSITIONS = [
        Review::ACTION_CHECK => [[Release::STATUS_UPLOADED, Release::STATUS_CHECKING], Release::STATUS_CHECKING],
        Review::ACTION_PASS_CHECKS => [[Release::STATUS_CHECKING], Release::STATUS_UPLOADED],
        Review::ACTION_FAIL_CHECKS => [[Release::STATUS_UPLOADED, Release::STATUS_CHECKING], Release::STATUS_FAILED_CHECKS],
        Review::ACTION_SUBMIT => [[Release::STATUS_UPLOADED, Release::STATUS_REJECTED], Release::STATUS_SUBMITTED],
        Review::ACTION_CLAIM => [[Release::STATUS_SUBMITTED], Release::STATUS_IN_REVIEW],
        Review::ACTION_APPROVE => [[Release::STATUS_SUBMITTED, Release::STATUS_IN_REVIEW], Release::STATUS_APPROVED],
        Review::ACTION_REJECT => [[Release::STATUS_SUBMITTED, Release::STATUS_IN_REVIEW, Release::STATUS_APPROVED], Release::STATUS_REJECTED],
        Review::ACTION_WITHDRAW => [[Release::STATUS_SUBMITTED, Release::STATUS_IN_REVIEW, Release::STATUS_APPROVED], Release::STATUS_UPLOADED],
        Review::ACTION_PUBLISH => [[Release::STATUS_APPROVED], Release::STATUS_PUBLISHED],
        Review::ACTION_YANK => [[Release::STATUS_PUBLISHED], Release::STATUS_YANKED],
    ];

    /** Past tense for error messages. */
    public const VERBS = [
        Review::ACTION_CHECK => 'checked',
        Review::ACTION_PASS_CHECKS => 'marked as passing checks',
        Review::ACTION_FAIL_CHECKS => 'marked as failing checks',
        Review::ACTION_SUBMIT => 'submitted',
        Review::ACTION_CLAIM => 'taken into review',
        Review::ACTION_APPROVE => 'approved',
        Review::ACTION_REJECT => 'rejected',
        Review::ACTION_WITHDRAW => 'withdrawn',
        Review::ACTION_PUBLISH => 'published',
        Review::ACTION_YANK => 'yanked',
    ];

    public static function can(Release $release, string $action): bool
    {
        [$from] = self::TRANSITIONS[$action] ?? [[]];

        return in_array($release->status, $from, true);
    }

    public static function assertCan(Release $release, string $action): void
    {
        if (! self::can($release, $action)) {
            throw InvalidReleaseTransition::make(self::VERBS[$action] ?? $action, str_replace('_', ' ', $release->status));
        }
    }

    /**
     * Apply a transition and record it. `$attributes` are saved on the release with the
     * status (published_at, yanked_at, meta…). Returns the refreshed release.
     */
    public function apply(Release $release, string $action, ?Account $actor = null, string $actorKind = Review::ACTOR_SYSTEM, ?string $reason = null, ?array $items = null, array $attributes = []): Release
    {
        [, $to] = self::TRANSITIONS[$action] ?? throw new \InvalidArgumentException("Unknown release action [{$action}].");

        return DB::transaction(function () use ($release, $action, $actor, $actorKind, $reason, $items, $attributes, $to) {
            /** @var Release $locked */
            $locked = Release::query()->whereKey($release->getKey())->lockForUpdate()->firstOrFail();
            self::assertCan($locked, $action);

            $from = $locked->status;
            $locked->forceFill(array_merge($attributes, ['status' => $to]))->save();

            Review::create([
                'release_uuid' => $locked->uuid,
                'reviewer_account_uuid' => $actor?->uuid,
                'actor' => $actor === null ? Review::ACTOR_SYSTEM : $actorKind,
                'action' => $action,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'items' => $items,
            ]);

            return $release->setRawAttributes($locked->getAttributes(), true)->unsetRelation('reviews');
        });
    }

    /**
     * Record the initial upload (no previous status).
     */
    public function recordUpload(Release $release, Account $actor): void
    {
        Review::create([
            'release_uuid' => $release->uuid,
            'reviewer_account_uuid' => $actor->uuid,
            'actor' => Review::ACTOR_PUBLISHER,
            'action' => Review::ACTION_UPLOAD,
            'from_status' => null,
            'to_status' => $release->status,
        ]);
    }
}
