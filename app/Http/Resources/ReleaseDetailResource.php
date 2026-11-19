<?php

namespace App\Http\Resources;

use App\Models\File;
use App\Models\ReleaseCheck;
use App\Support\Http;

/**
 * A release as its publisher and the review console see it: files, checks, submission state,
 * the D6 policy outcome, the review timeline and, under `extension`, the listing as the
 * catalog would show it (the staff listing preview; fields whose relations were not loaded
 * are left out).
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
            'extension' => $this->whenLoaded('extension', fn () => $this->extensionPreview($request)),
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

    protected function extensionPreview($request): array
    {
        $extension = $this->extension;
        $loaded = fn (string $relation) => $extension->relationLoaded($relation);

        $preview = [
            'id' => $extension->public_id,
            'slug' => $extension->slug,
            'name' => $extension->name,
            'status' => $extension->status,
            'organization' => $loaded('organization') && $extension->organization
                ? ['id' => $extension->organization->public_id, 'slug' => $extension->organization->slug, 'name' => $extension->organization->name, 'is_verified' => $extension->organization->verified_at !== null]
                : null,
            'subtitle' => $extension->subtitle,
            'description' => $extension->description,
            'promotional_text' => $extension->promotional_text,
            'tags' => $extension->tags ?? [],
            'languages' => $extension->languages ?? [],
            'hosting' => $extension->hosting,
            'fa_icon' => $extension->fa_icon,
            'website_url' => $extension->website_url,
            'repo_url' => $extension->repo_url,
            'support_url' => $extension->support_url,
            'privacy_url' => $extension->privacy_url,
            'tos_url' => $extension->tos_url,
            'copyright' => $extension->copyright,
        ];

        if ($loaded('icon')) {
            $preview['icon_url'] = $extension->icon?->url;
        }
        if ($loaded('screenshots')) {
            $preview['screenshots'] = ExtensionResource::screenshotList($extension);
        }
        if ($loaded('category')) {
            $preview['category'] = $extension->category ? ['id' => $extension->category->public_id, 'slug' => $extension->category->slug, 'name' => $extension->category->name] : null;
        }
        if ($loaded('price')) {
            $preview['price'] = (new PriceResource($extension->price))->resolve($request);
        }

        return $preview;
    }
}
