<?php

namespace App\Http\Resources;

/**
 * One entry of a release's status timeline.
 */
class ReviewResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'action' => $this->action,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor' => $this->actor,
            'by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->public_id, 'name' => $this->reviewer->name] : null),
            'reason' => $this->reason,
            'items' => $this->items ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
