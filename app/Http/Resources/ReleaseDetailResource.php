<?php

namespace App\Http\Resources;

use App\Models\File;
use App\Models\ReleaseCheck;
use App\Support\Http;

/**
 * A release as its publisher and the review console see it: files, checks, submission state,
 * the D6 policy outcome and the review timeline.
 */
class ReleaseDetailResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        $file = fn (?File $file) => $file === null ? null : [
            'id' => $file->public_id,
            'name' => $file->name,
            'original_filename' => $file->original_filename,
            'size' => (int) $file->size,
            'sha256' => $file->checksum,
            'integrity' => $file->integrity,
        ];

        $checks = $this->relationLoaded('checks') ? $this->checks : null;

        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'extension' => $this->whenLoaded('extension', fn () => [
                'id' => $this->extension->public_id,
                'slug' => $this->extension->slug,
                'name' => $this->extension->name,
                'status' => $this->extension->status,
                'organization' => $this->extension->relationLoaded('organization') && $this->extension->organization
                    ? ['id' => $this->extension->organization->public_id, 'slug' => $this->extension->organization->slug, 'name' => $this->extension->organization->name]
                    : null,
            ]),
            'version' => $this->version,
            'channel' => $this->channel,
            'status' => $this->status,
            'compat' => $this->compat ?? [],
            'notes' => $this->notes,
            'size_bytes' => (int) $this->size_bytes,
            'checksum' => $this->checksum,
            'files' => [
                'engine' => $file($this->engineTarball),
                'api' => $file($this->apiTarball),
                'dist' => $file($this->engineDist),
            ],
            'checks_passed' => $checks === null || $this->metaValue('checks_completed_at') === null ? null : $this->checksPassed(),
            'checks' => $checks === null ? [] : ReleaseCheckResource::collection($checks->sortBy(fn ($check) => array_search($check->check, ReleaseCheck::CHECKS, true))->values())->resolve($request),
            'submission' => [
                'requested' => (bool) $this->metaValue('submission.requested', false),
                'submitted_at' => $this->metaValue('submission.submitted_at'),
                'errors' => $this->metaValue('submission.errors', []),
            ],
            'review' => [
                'required' => $this->metaValue('review_policy.required'),
                'reasons' => (object) $this->metaValue('review_policy.reasons', []),
            ],
            'dist' => $this->metaValue('dist'),
            'reviews' => $this->whenLoaded('reviews', fn () => ReviewResource::collection($this->reviews)->resolve($request), []),
            'manifest' => $this->manifest ?? [],
            'published_at' => $this->published_at,
            'yanked_at' => $this->yanked_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
