<?php

namespace App\Http\Resources;

class InstallResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'extension' => $this->whenLoaded('extension', fn () => (new ExtensionReferenceResource($this->extension))->resolve($request)),
            'release' => $this->whenLoaded('release', fn () => $this->release ? (new ReleaseResource($this->release))->resolve($request) : null),
            'status' => $this->status,
            'installed_by' => $this->installed_by,
            'error' => $this->error,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
