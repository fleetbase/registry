<?php

namespace App\Http\Resources;

use App\Support\Http;

class CategoryResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'tags' => $this->tags ?? [],
            'sort' => $this->sort,
            'extensions_count' => $this->whenCounted('extensions'),
        ];
    }
}
