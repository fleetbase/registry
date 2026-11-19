<?php

namespace App\Http\Resources;

class LicenceActivationResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'licence' => $this->whenLoaded('licence', fn () => (new LicenceResource($this->licence))->resolve($request)),
            'release' => $this->whenLoaded('release', fn () => $this->release?->version),
            'activated_at' => $this->activated_at,
            'deactivated_at' => $this->deactivated_at,
        ];
    }
}
