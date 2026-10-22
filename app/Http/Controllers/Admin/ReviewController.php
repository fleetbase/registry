<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publisher\PublisherController;
use App\Http\Requests\Admin\ReviewDecisionRequest;
use App\Http\Requests\Admin\SuspendExtensionRequest;
use App\Http\Resources\PublisherExtensionResource;
use App\Http\Resources\ReleaseDetailResource;
use App\Models\Extension;
use App\Models\Release;
use App\Releases\ReleasePipeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff review console API: queue, claim, approve, reject, publish, suspend. Needs a token
 * with the `review` scope and a membership in an `is_staff` organization.
 */
class ReviewController extends Controller
{
    public function __construct(protected ReleasePipeline $pipeline) {}

    /**
     * GET /v1/admin/releases?status=submitted,in_review&extension=&limit= — oldest first.
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
            ->with(['extension.organization', 'engineTarball', 'apiTarball', 'engineDist', 'checks'])
            ->whereIn('status', $statuses)
            ->when($request->filled('extension'), fn (Builder $q) => $q->whereHas('extension', fn (Builder $q) => $q->where('slug', $request->input('extension'))->orWhere('public_id', $request->input('extension'))))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->paginate((int) $request->input('limit', 25))
            ->withQueryString();

        return ReleaseDetailResource::collection($releases);
    }

    public function show(string $release): ReleaseDetailResource
    {
        return new ReleaseDetailResource(PublisherController::releaseDetail(Release::findByIdOrFail($release)));
    }

    /**
     * POST /v1/admin/releases/{id}/claim — submitted → in_review.
     */
    public function claim(Request $request, string $release): ReleaseDetailResource
    {
        $release = $this->pipeline->claim(Release::findByIdOrFail($release), $request->user());

        return new ReleaseDetailResource(PublisherController::releaseDetail($release));
    }

    /**
     * POST /v1/admin/releases/{id}/approve — optional reason and items; publishes unless
     * `publish` is false.
     */
    public function approve(ReviewDecisionRequest $request, string $release): ReleaseDetailResource
    {
        $release = $this->pipeline->approve(
            Release::findByIdOrFail($release),
            $request->user(),
            $request->validated('reason'),
            $request->items(),
            $request->boolean('publish', true),
        );

        return new ReleaseDetailResource(PublisherController::releaseDetail($release));
    }

    /**
     * POST /v1/admin/releases/{id}/reject — required reason and items.
     */
    public function reject(ReviewDecisionRequest $request, string $release): ReleaseDetailResource
    {
        $release = $this->pipeline->reject(Release::findByIdOrFail($release), $request->user(), $request->validated('reason'), $request->items());

        return new ReleaseDetailResource(PublisherController::releaseDetail($release));
    }

    /**
     * POST /v1/admin/releases/{id}/publish — an approved release.
     */
    public function publish(Request $request, string $release): ReleaseDetailResource
    {
        $release = $this->pipeline->publish(Release::findByIdOrFail($release), $request->user());

        return new ReleaseDetailResource(PublisherController::releaseDetail($release));
    }

    /**
     * POST /v1/admin/extensions/{slug}/suspend — required reason.
     */
    public function suspend(SuspendExtensionRequest $request, string $slug): PublisherExtensionResource
    {
        $extension = Extension::query()->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))->firstOrFail();
        $extension = $this->pipeline->suspend($extension, $request->user(), $request->validated('reason'));

        return new PublisherExtensionResource($extension->load(['organization', 'category', 'packages', 'price']));
    }
}
