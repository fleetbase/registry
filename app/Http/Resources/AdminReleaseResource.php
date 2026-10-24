<?php

namespace App\Http\Resources;

use App\Models\Release;
use App\Releases\PreviousReleases;
use App\Support\Http;

/**
 * A release as the staff review console sees it: the release detail plus what it updates.
 * `previous_version` is the extension's latest published (not yanked) release before this
 * one, or null for a first release (see `PreviousReleases`). The single-release responses
 * also carry `previous` (id, version, published_at, compat, manifest, size_bytes) so the
 * console can say what changed.
 */
class AdminReleaseResource extends ReleaseDetailResource
{
    /** Include the `previous` object (needs the previous release's full columns loaded). */
    public bool $withPrevious = false;

    /**
     * One release with `previous`: loads its predecessor in full.
     */
    public static function detailed(Release $release): static
    {
        PreviousReleases::attach([$release], ['*']);

        return tap(new static($release), fn (self $resource) => $resource->withPrevious = true);
    }

    public function toArray($request): array
    {
        $previous = $this->relationLoaded('previousRelease') ? $this->previousRelease : null;
        $data = parent::toArray($request);
        $data['previous_version'] = $previous?->version;

        if ($this->withPrevious) {
            $data['previous'] = $previous === null ? null : [
                'id' => Http::isInternalRequest() ? $previous->id : $previous->public_id,
                'version' => $previous->version,
                'published_at' => $previous->published_at,
                'compat' => $previous->compat ?? [],
                'manifest' => $previous->manifest ?? [],
                'size_bytes' => (int) $previous->size_bytes,
            ];
        }

        return $data;
    }
}
