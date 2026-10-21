<?php

namespace App\Http\Resources;

use App\Support\Http;

class AccountResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id' => $this->when(Http::isInternalRequest(), $this->public_id),
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at,
            'organizations' => $this->whenLoaded('organizations', fn () => $this->organizations->map(fn ($organization) => [
                'id' => $organization->public_id,
                'slug' => $organization->slug,
                'name' => $organization->name,
                'role' => $organization->pivot->role,
                'is_personal' => $organization->is_personal,
            ])->all()),
            'last_login_at' => $this->last_login_at,
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
        ];
    }
}
