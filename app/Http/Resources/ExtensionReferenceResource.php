<?php

namespace App\Http\Resources;

use App\Support\Http;

/**
 * Minimal extension reference embedded in instance, install and licence payloads.
 */
class ExtensionReferenceResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'slug' => $this->slug,
            'name' => $this->name,
        ];
    }
}
