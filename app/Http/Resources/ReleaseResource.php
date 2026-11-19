<?php

namespace App\Http\Resources;

use App\Models\Release;
use App\Support\Http;

class ReleaseResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'version' => $this->version,
            'channel' => $this->channel,
            'status' => $this->status,
            'compat' => $this->compat ?? [],
            'size_bytes' => (int) $this->size_bytes,
            'checksum' => $this->checksum,
            'notes' => $this->notes,
            'dist' => $this->whenLoaded('engineDist', fn () => static::dist($this->resource)),
            'published_at' => $this->published_at,
            'yanked_at' => $this->yanked_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The prebuilt engine dist of a release (its `engineDist` loaded), or null: where an
     * instance downloads it (`url`, `GET /v1/instances/me/releases/{release}/dist` with its
     * instance token), what to verify it with, and the `@fleetbase/ember-ui` /
     * `@fleetbase/ember-core` versions it was built against.
     *
     * @return array{url: string, size: int, sha256: ?string, integrity: ?string, built_against: array<string, string>}|null
     */
    public static function dist(Release $release): ?array
    {
        $file = $release->engineDist;
        if ($file === null) {
            return null;
        }

        return [
            'url' => route('v1.instances.me.releases.dist', ['release' => $release->public_id]),
            'size' => (int) $file->size,
            'sha256' => $file->checksum,
            'integrity' => $file->integrity,
            'built_against' => (array) $release->metaValue('dist.built_against', []),
        ];
    }
}
