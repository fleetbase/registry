<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Admin\ReviewController;
use App\Models\Account;
use App\Models\Extension;
use App\Models\Release;
use App\Models\Review;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fleetbase staff review console: the queue, one release, releases waiting to be published
 * and suspended listings. Decisions are POSTs to /v1/admin from the page.
 */
class ReviewPageController extends WebController
{
    /** Queue tabs and the release statuses each lists. */
    public const TABS = [
        'waiting' => [Release::STATUS_SUBMITTED, Release::STATUS_IN_REVIEW],
        'decided' => [Release::STATUS_REJECTED, Release::STATUS_PUBLISHED],
        'failed' => [Release::STATUS_FAILED_CHECKS],
    ];

    /**
     * GET /review?tab=waiting|decided|failed — oldest first. `claimers` names the reviewer who
     * took each in-review release, keyed by release id (the queue API leaves the timeline out).
     */
    public function queue(Request $request): Response
    {
        $this->authorizeStaff($request);
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? $request->query('tab') : 'waiting';
        $releases = $this->api($request, '/v1/admin/releases', ['status' => implode(',', self::TABS[$tab]), 'limit' => 100], ReviewController::class, 'queue');

        return Inertia::render('Review/Queue', [
            'tab' => $tab,
            'releases' => $releases,
            'claimers' => $this->latestReviewers($releases['data'] ?? [], Release::STATUS_IN_REVIEW, Review::ACTION_CLAIM),
            'counts' => $this->counts(),
        ]);
    }

    /**
     * GET /review/publish — approved releases waiting to go live (with who approved them), and
     * suspended listings (with the reason and who suspended them).
     */
    public function pending(Request $request): Response
    {
        $this->authorizeStaff($request);
        $releases = $this->api($request, '/v1/admin/releases', ['status' => Release::STATUS_APPROVED, 'limit' => 100], ReviewController::class, 'queue');

        $suspended = Extension::query()
            ->where('status', Extension::STATUS_SUSPENDED)
            ->with(['organization', 'icon'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();
        $suspenders = Account::query()
            ->whereIn('public_id', $suspended->pluck('meta.suspension.by')->filter()->unique()->values())
            ->pluck('name', 'public_id');

        return Inertia::render('Review/Pending', [
            'releases' => $releases,
            'approvers' => $this->latestReviewers($releases['data'] ?? [], Release::STATUS_APPROVED, Review::ACTION_APPROVE),
            'suspended' => $suspended->map(fn (Extension $extension) => [
                'id' => $extension->public_id,
                'slug' => $extension->slug,
                'name' => $extension->name,
                'icon_url' => $extension->icon?->url,
                'organization' => $extension->organization?->only(['slug', 'name']),
                'suspension' => $this->suspension($extension, $suspenders->all()),
            ])->all(),
            'counts' => $this->counts(),
        ]);
    }

    /**
     * GET /review/releases/{release} — the release with its listing preview, the review
     * requirements, and the listing's suspension when it is suspended.
     */
    public function release(Request $request, string $release): Response
    {
        $this->authorizeStaff($request);
        $model = Release::findByIdOrFail($release);
        $extension = $model->extension;
        $suspension = $extension?->status === Extension::STATUS_SUSPENDED
            ? $this->suspension($extension, Account::query()->where('public_id', $extension->meta['suspension']['by'] ?? null)->pluck('name', 'public_id')->all())
            : null;

        return Inertia::render('Review/Release', [
            'release' => $this->api($request, "/v1/admin/releases/{$release}", [], ReviewController::class, 'show', ['release' => $release]),
            'reviewItems' => $this->api($request, '/v1/admin/review-items', [], ReviewController::class, 'reviewItems'),
            'suspension' => $suspension,
        ]);
    }

    protected function authorizeStaff(Request $request): void
    {
        $account = $request->user();

        abort_unless($account instanceof Account && $account->isStaff(), 403, 'The review console is for Fleetbase staff.');
    }

    /**
     * The staff member behind the latest `$action` of each listed release in `$status`, keyed
     * by release id: `{id, name, at}`.
     *
     * @param  list<array<string, mixed>>  $releases  ReleaseDetailResource arrays
     * @return array<string, array{id: string|null, name: string|null, at: string|null}>
     */
    protected function latestReviewers(array $releases, string $status, string $action): array
    {
        $ids = collect($releases)->where('status', $status)->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Release::query()
            ->whereIn('public_id', $ids)
            ->with(['reviews' => fn ($query) => $query->where('action', $action)->with('reviewer')])
            ->get()
            ->mapWithKeys(function (Release $release) {
                $review = $release->reviews->sortBy('id')->last();

                return [$release->public_id => [
                    'id' => $review?->reviewer?->public_id,
                    'name' => $review?->reviewer?->name,
                    'at' => $review?->created_at?->toIso8601String(),
                ]];
            })
            ->filter(fn (array $reviewer) => $reviewer['name'] !== null)
            ->all();
    }

    /**
     * A listing's suspension (`reason`, `at`, `by`) with the suspending account's name.
     *
     * @param  array<string, string>  $names  account public id => name
     */
    protected function suspension(Extension $extension, array $names): ?array
    {
        $suspension = $extension->meta['suspension'] ?? null;
        if (! is_array($suspension)) {
            return null;
        }

        return [
            'reason' => $suspension['reason'] ?? null,
            'at' => $suspension['at'] ?? null,
            'by' => $suspension['by'] ?? null,
            'by_name' => $names[$suspension['by'] ?? ''] ?? null,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function counts(): array
    {
        return [
            'waiting' => Release::query()->whereIn('status', self::TABS['waiting'])->count(),
            'publish' => Release::query()->where('status', Release::STATUS_APPROVED)->count(),
            'suspended' => Extension::query()->where('status', Extension::STATUS_SUSPENDED)->count(),
        ];
    }
}
