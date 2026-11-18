<?php

namespace App\Http\Resources;

use App\Support\Http;

class TrustedPublisherResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'provider' => $this->provider,
            'repository' => $this->repository,
            'workflow' => $this->workflow,
            'environment' => $this->environment,
            'refs' => $this->refs ?? [],
            'repository_id' => $this->repository_id,
            'repository_owner_id' => $this->repository_owner_id,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['name' => $this->createdBy->name] : null),
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
        ];
    }
}
