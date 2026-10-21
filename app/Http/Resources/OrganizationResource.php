<?php

namespace App\Http\Resources;

use App\Support\Http;

class OrganizationResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'slug' => $this->slug,
            'name' => $this->name,
            'website' => $this->website,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'is_personal' => $this->is_personal,
            'is_verified' => $this->verified_at !== null,
            'verified_at' => $this->verified_at,
            'extensions_count' => $this->whenCounted('extensions'),
            'extensions' => $this->whenLoaded('extensions', fn () => ExtensionSummaryResource::collection($this->extensions)->resolve($request)),
            'created_at' => $this->created_at,
        ];
    }
}
