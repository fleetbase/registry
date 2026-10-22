<?php

namespace App\Http\Resources;

use App\Support\Http;

/**
 * An instance as returned to itself and to its organization. Never includes the token.
 */
class InstanceResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'name' => $this->name,
            'host' => $this->host,
            'kind' => $this->kind,
            'console_version' => $this->console_version,
            'api_version' => $this->api_version,
            'status' => $this->status,
            'organization' => $this->whenLoaded('organization', fn () => (new OrganizationResource($this->organization))->resolve($request)),
            'last_seen_at' => $this->last_seen_at,
            'created_at' => $this->created_at,
        ];
    }
}
