<?php

namespace App\Http\Resources;

use App\Support\Http;

/**
 * A licence with its seat usage. `max_activations` null means unlimited.
 * `activated_here` is included when the query selected it (instance routes).
 */
class LicenceResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'extension' => $this->whenLoaded('extension', fn () => $this->extension ? (new ExtensionReferenceResource($this->extension))->resolve($request) : null),
            'kind' => $this->kind,
            'status' => $this->status,
            'max_activations' => $this->max_activations,
            'seats_used' => $this->whenCounted('activeActivations', fn () => (int) $this->active_activations_count),
            'activated_here' => $this->when(array_key_exists('activated_here', $this->resource->getAttributes()), fn () => (bool) $this->resource->getAttribute('activated_here')),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
