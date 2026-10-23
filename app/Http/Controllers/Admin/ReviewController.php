<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publisher\ExtensionController as PublisherExtensionController;
use App\Http\Controllers\Publisher\PublisherController;
use App\Http\Requests\Admin\ReviewDecisionRequest;
use App\Http\Requests\Admin\SuspendExtensionRequest;
use App\Http\Resources\AdminReleaseResource;
use App\Http\Resources\PublisherExtensionResource;
use App\Models\Event;
use App\Models\Extension;
use App\Models\File;
use App\Models\Release;
use App\Releases\PreviousReleases;
use App\Releases\ReleasePipeline;
use App\Services\ReleaseStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff review console API: queue, claim, approve, reject, publish, archive downloads,
 * suspend and unsuspend. Needs a token (or website session) with the `review` scope and a
 * membership in an `is_staff` organization.
 */
class ReviewController extends Controller
{
    public function __construct(protected ReleasePipeline $pipeline) {}

    /**
     * GET /v1/admin/releases?status=submitted,in_review&extension=&limit= — oldest first, each
     * with `previous_version` (the live version it updates, null for a first release).
     */
    public function queue(Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'extension' => ['nullable', 'string', 'max:191'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $statuses = $request->filled('status') ? explode(',', $request->input('status')) : Release::QUEUE_STATUSES;
        $request->merge(['statuses' => $statuses])->validate(['statuses.*' => [Rule::in(Release::STATUSES)]]);

        $releases = Release::query()
            ->with(array_merge(PublisherController::RELEASE_LISTING, ['engineTarball', 'apiTarball', 'engineDist', 'checks']))
            ->whereIn('status', $statuses)
            ->when($request->filled('extension'), fn (Builder $q) => $q->whereHas('extension', fn (Builder $q) => $q->where('slug', $request->input('extension'))->orWhere('public_id', $request->input('extension'))))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->paginate((int) $request->input('limit', 25))
            ->withQueryString();
        PreviousReleases::attach($releases->getCollection());

        return AdminReleaseResource::collection($releases);
    }

    /**
     * GET /v1/admin/review-items — the review requirement codes for approve and reject items.
     */
    public function reviewItems(): JsonResponse
    {
        return response()->json(array_map(fn (array $item) => ['code' => $item['code'], 'label' => $item['label']], config('registry.review.items', [])));
    }

    /**
     * GET /v1/admin/releases/{id} — checks, policy reasons, timeline, the listing preview and
     * `previous_version` / `previous` (the live release it updates, for what changed).
     */
    public function show(string $release): AdminReleaseResource
    {
        return AdminReleaseResource::detailed(PublisherController::releaseDetail(Release::findByIdOrFail($release)));
    }

    /**
     * POST /v1/admin/releases/{id}/claim — submitted → in_review.
     */
    public function claim(Request $request, string $release): AdminReleaseResource
    {
        $release = $this->pipeline->claim(Release::findByIdOrFail($release), $request->user());

        return AdminReleaseResource::detailed(PublisherController::releaseDetail($release));
    }

    /**
     * POST /v1/admin/releases/{id}/approve — optional reason and items; publishes unless
     * `publish` is false.
     */
    public function approve(ReviewDecisionRequest $request, string $release): AdminReleaseResource
    {
        $release = $this->pipeline->approve(
            Release::findByIdOrFail($release),
            $request->user(),
            $request->validated('reason'),
            $request->items(),
            $request->boolean('publish', true),
        );

        return AdminReleaseResource::detailed(PublisherController::releaseDetail($release));
    }

    /**
     * POST /v1/admin/releases/{id}/reject — required reason and items. Allowed while the
     * release is submitted, in review or approved but not yet published (request changes on an
     * approval); anything else is a 409.
     */
    public function reject(ReviewDecisionRequest $request, string $release): AdminReleaseResource
    {
        $release = $this->pipeline->reject(Release::findByIdOrFail($release), $request->user(), $request->validated('reason'), $request->items());

        return AdminReleaseResource::detailed(PublisherController::releaseDetail($release));
    }

    /**
     * POST /v1/admin/releases/{id}/publish — an approved release.
     */
    public function publish(Request $request, string $release): AdminReleaseResource
    {
        $release = $this->pipeline->publish(Release::findByIdOrFail($release), $request->user());

        return AdminReleaseResource::detailed(PublisherController::releaseDetail($release));
    }

    /**
     * GET /v1/admin/releases/{id}/files/{kind} — the stored `engine`, `api` or `dist` archive
     * as a download, for staff to inspect. 404 when the release has no such file.
     */
    public function download(Request $request, string $release, string $kind, ReleaseStorage $storage): Response
    {
        $release = Release::findByIdOrFail($release);
        $file = File::find($release->{ReleasePipeline::FILE_COLUMNS[$kind]});

        if ($file === null) {
            throw new NotFoundHttpException("This release has no {$kind} archive.");
        }

        Event::record('release.downloaded', $release, $request->user(), ['kind' => $kind, 'file' => $file->public_id], $request->ip());

        return $storage->download($file, $request, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * POST /v1/admin/extensions/{slug}/suspend — required reason.
     */
    public function suspend(SuspendExtensionRequest $request, string $slug): PublisherExtensionResource
    {
        $extension = Extension::query()->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))->firstOrFail();
        $extension = $this->pipeline->suspend($extension, $request->user(), $request->validated('reason'));

        return new PublisherExtensionResource($extension->load(PublisherExtensionController::DETAIL));
    }

    /**
     * POST /v1/admin/extensions/{slug}/unsuspend — optional reason. Restores the status the
     * listing had when it was suspended.
     */
    public function unsuspend(Request $request, string $slug): PublisherExtensionResource
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:5000']]);

        $extension = Extension::query()->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))->firstOrFail();
        $extension = $this->pipeline->unsuspend($extension, $request->user(), $request->input('reason'));

        return new PublisherExtensionResource($extension->load(PublisherExtensionController::DETAIL));
    }
}
