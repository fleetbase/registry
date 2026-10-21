<?php

namespace App\Http\Resources;

use App\Support\Http;

class ReleaseResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'version' => $this->version,
            'channel' => $this->channel,
            'status' => $this->status,
            'compat' => $this->compat ?? [],
            'size_bytes' => (int) $this->size_bytes,
            'checksum' => $this->checksum,
            'notes' => $this->notes,
            'published_at' => $this->published_at,
            'yanked_at' => $this->yanked_at,
            'created_at' => $this->created_at,
        ];
    }
}
