<?php

namespace App\Http\Resources;

use App\Support\Http;

/**
 * A token as shown in lists: never the plaintext, only prefix and last four.
 */
class TokenResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'name' => $this->name,
            'masked' => $this->masked,
            'prefix' => $this->prefix,
            'last_four' => $this->last_four,
            'scopes' => $this->scopes ?? [],
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->public_id, null),
            'expires_at' => $this->expires_at,
            'last_used_at' => $this->last_used_at,
            'last_used_ip' => $this->last_used_ip,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
        ];
    }
}
