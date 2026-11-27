<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Resources\TrustedPublisherResource;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Token;
use App\Models\TrustedPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GitHub Actions workflows that may publish an extension without a stored token (owners and
 * admins). See `App\TrustedPublishing\TokenExchange`.
 */
class TrustedPublisherController extends PublisherController
{
    public const MAX_PER_EXTENSION = 10;

    /**
     * GET /v1/publisher/extensions/{slug}/trusted-publishers
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $extension = $this->managed($request, $slug);

        return response()->json(TrustedPublisherResource::collection(
            $extension->trustedPublishers()->with('createdBy')->orderBy('created_at')->get()
        )->resolve($request));
    }

    /**
     * POST /v1/publisher/extensions/{slug}/trusted-publishers — `{repository: "owner/repo",
     * workflow: "release.yml", environment?, refs: ["refs/tags/v*"]}`. The account creating it
     * is the one releases are published as; it must keep a publishing role in the organization.
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $extension = $this->managed($request, $slug);
        $input = $request->validate([
            'repository' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9._-]{1,100}$/'],
            'workflow' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9._-]+\.ya?ml$/'],
            'environment' => ['nullable', 'string', 'max:200', 'regex:/^[^\s\/][^\/]*$/'],
            'refs' => ['required', 'array', 'min:1', 'max:20'],
            'refs.*' => ['required', 'string', 'max:200', 'regex:/^refs\/(heads|tags)\/\S+$/'],
        ], [
            'workflow.regex' => 'The workflow is the file name under .github/workflows, e.g. release.yml.',
            'refs.*.regex' => 'Each ref is a git ref pattern such as refs/tags/v* or refs/heads/main.',
        ]);

        if ($extension->trustedPublishers()->count() >= self::MAX_PER_EXTENSION) {
            throw ValidationException::withMessages(['repository' => 'An extension can have at most '.self::MAX_PER_EXTENSION.' trusted publishers.']);
        }

        $publisher = $extension->trustedPublishers()->create([
            'created_by_account_uuid' => $this->account($request)->uuid,
            'provider' => TrustedPublisher::PROVIDER_GITHUB,
            'repository' => $input['repository'],
            'workflow' => $input['workflow'],
            'environment' => $input['environment'] ?? null,
            'refs' => array_values(array_unique($input['refs'])),
        ]);

        Event::record('trusted_publisher.created', $publisher, $this->account($request), [
            'extension' => $extension->public_id, 'repository' => $publisher->repository, 'workflow' => $publisher->workflow,
        ]);

        return (new TrustedPublisherResource($publisher->load('createdBy')))->response()->setStatusCode(201);
    }

    /**
     * DELETE /v1/publisher/extensions/{slug}/trusted-publishers/{id} — publish tokens it issued
     * are revoked too.
     */
    public function destroy(Request $request, string $slug, string $id): Response
    {
        $extension = $this->managed($request, $slug);
        $publisher = $extension->trustedPublishers()->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))->first()
            ?? throw new NotFoundHttpException('Trusted publisher not found.');

        Token::query()->active()->where('trusted_publisher_uuid', $publisher->uuid)->update(['revoked_at' => now()]);
        $publisher->delete();

        Event::record('trusted_publisher.deleted', $publisher, $this->account($request), ['extension' => $extension->public_id, 'repository' => $publisher->repository]);

        return response()->noContent();
    }

    protected function managed(Request $request, string $slug): Extension
    {
        $extension = $this->findExtension($request, $slug);
        $this->assertManages($request, $extension);

        return $extension;
    }
}
