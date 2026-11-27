<?php

namespace App\Http\Resources;

/**
 * A membership as the organization's members see it: the account and its role.
 */
class MemberResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        $account = $this->relationLoaded('account') ? $this->account : null;

        return [
            'id' => $account?->public_id,
            'name' => $account?->name,
            'username' => $account?->username,
            'email' => $account?->email,
            'avatar_url' => $account?->avatar_url,
            'role' => $this->role,
            'joined_at' => $this->created_at,
        ];
    }
}
