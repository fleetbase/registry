<?php

namespace App\Http\Resources;

use App\Models\Instance;
use App\Support\Http;

/**
 * An access grant (a `grant` licence) as the extension's owner sees it: the organization, the
 * instances it is limited to (empty: every instance), and the instances holding a seat.
 */
class GrantResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        $instance = fn (?Instance $instance) => $instance ? [
            'id' => $instance->public_id,
            'name' => $instance->name,
            'host' => $instance->host,
            'kind' => $instance->kind,
            'status' => $instance->trashed() ? Instance::STATUS_REVOKED : $instance->status,
        ] : null;

        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? [
                'id' => $this->organization->public_id,
                'slug' => $this->organization->slug,
                'name' => $this->organization->name,
            ] : null),
            'status' => $this->resource->isActive() ? 'active' : ($this->status === 'revoked' ? 'revoked' : 'expired'),
            'instances' => $this->whenLoaded('allowedInstances', fn () => $this->allowedInstances->map($instance)->values()->all(), []),
            'max_activations' => $this->max_activations,
            'seats_used' => $this->whenCounted('activeActivations', fn () => (int) $this->active_activations_count),
            'activations' => $this->whenLoaded('activeActivations', fn () => $this->activeActivations->map(fn ($activation) => [
                'instance' => $activation->relationLoaded('instance') ? $instance($activation->instance) : null,
                'release' => $activation->relationLoaded('release') ? $activation->release?->version : null,
                'activated_at' => $activation->activated_at,
            ])->values()->all(), []),
            'note' => $this->meta['note'] ?? null,
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->meta['revoked_at'] ?? null,
            'revoked_reason' => $this->meta['revoked_note'] ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
