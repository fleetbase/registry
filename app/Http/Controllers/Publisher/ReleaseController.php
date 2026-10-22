<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Requests\Publisher\UploadReleaseRequest;
use App\Http\Resources\ReleaseDetailResource;
use App\Models\Extension;
use App\Releases\ReleasePipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ReleaseController extends PublisherController
{
    public function __construct(protected ReleasePipeline $pipeline) {}

    /**
     * GET /v1/publisher/extensions/{slug}/releases
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $extension = $this->findExtension($request, $slug);
        $releases = $extension->releases()
            ->with(['extension.organization', 'engineTarball', 'apiTarball', 'engineDist', 'checks'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(ReleaseDetailResource::collection($releases)->resolve($request));
    }

    /**
     * POST /v1/publisher/extensions/{slug}/releases — multipart `manifest`, `engine`, `api`,
     * optional `dist`, `channel`, `notes`, `submit`. Stores the files and queues the checks;
     * returns the release (201) with whatever checks have already run.
     */
    public function store(UploadReleaseRequest $request, string $slug): JsonResponse
    {
        $extension = $this->findExtension($request, $slug);
        if ($extension->status === Extension::STATUS_SUSPENDED) {
            throw new AccessDeniedHttpException('This extension is suspended.');
        }

        $release = $this->pipeline->upload(
            $extension,
            $this->account($request),
            $request->manifest(),
            $request->channel(),
            $request->input('notes'),
            $request->uploads(),
            $request->boolean('submit'),
        );

        return (new ReleaseDetailResource(self::releaseDetail($release->fresh())))->response()->setStatusCode(201);
    }

    /**
     * GET /v1/publisher/releases/{id} — checks, submission state, review status and reasons.
     */
    public function show(Request $request, string $release): ReleaseDetailResource
    {
        return new ReleaseDetailResource(self::releaseDetail($this->findRelease($request, $release)));
    }

    /**
     * POST /v1/publisher/releases/{id}/submit — 200 once submitted (or published under D6),
     * 202 while checks are still running (submitted when they pass).
     */
    public function submit(Request $request, string $release): JsonResponse
    {
        $release = $this->findRelease($request, $release);
        $pending = $this->pipeline->checksPending($release);
        $release = $this->pipeline->submit($release, $this->account($request));

        return (new ReleaseDetailResource(self::releaseDetail($release)))->response()->setStatusCode($pending ? 202 : 200);
    }

    /**
     * POST /v1/publisher/releases/{id}/withdraw
     */
    public function withdraw(Request $request, string $release): ReleaseDetailResource
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $release = $this->pipeline->withdraw($this->findRelease($request, $release), $this->account($request), $request->input('reason'));

        return new ReleaseDetailResource(self::releaseDetail($release));
    }

    /**
     * POST /v1/publisher/releases/{id}/yank
     */
    public function yank(Request $request, string $release): ReleaseDetailResource
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $release = $this->pipeline->yank($this->findRelease($request, $release), $this->account($request), $request->input('reason'));

        return new ReleaseDetailResource(self::releaseDetail($release));
    }
}
