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
            'github_login' => $this->github_login,
            // False for accounts created with GitHub until they set one with Forgot password.
            'has_password' => $this->password !== null,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at,
            'is_staff' => $this->relationLoaded('organizations')
                ? $this->organizations->contains(fn ($organization) => $organization->is_staff === true)
                : $this->resource->isStaff(),
            'organizations' => $this->whenLoaded('organizations', fn () => $this->organizations->map(fn ($organization) => [
                'id' => $organization->public_id,
                'slug' => $organization->slug,
                'name' => $organization->name,
                'role' => $organization->pivot->role,
                'is_personal' => $organization->is_personal,
                'is_staff' => (bool) $organization->is_staff,
                'verified_at' => $organization->verified_at,
            ])->all()),
            'last_login_at' => $this->last_login_at,
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
        ];
    }
}
