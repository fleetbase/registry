<?php

namespace App\Http\Resources;

/**
 * The public face of an organization on the catalog.
 */
class PublisherResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'name' => $this->name,
            'website' => $this->website,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'is_verified' => $this->verified_at !== null,
            'extensions_count' => $this->whenCounted('extensions'),
            'extensions' => $this->whenLoaded('extensions', fn () => ExtensionSummaryResource::collection($this->extensions)->resolve($request)),
            'created_at' => $this->created_at,
        ];
    }
}
