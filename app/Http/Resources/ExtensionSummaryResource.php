<?php

namespace App\Http\Resources;

use App\Support\Http;

/**
 * Listing row for catalog pages: publisher, category, latest release, install count, compat, price.
 */
class ExtensionSummaryResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        $latest = $this->whenLoaded('latestRelease', fn () => $this->latestRelease);

        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
            'slug' => $this->slug,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'fa_icon' => $this->fa_icon,
            'icon_url' => $this->whenLoaded('icon', fn () => $this->icon?->url, null),
            'tags' => $this->tags ?? [],
            'hosting' => $this->hosting,
            'status' => $this->status,
            'publisher' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization->public_id,
                'slug' => $this->organization->slug,
                'name' => $this->organization->name,
                'is_verified' => $this->organization->verified_at !== null,
            ]),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->public_id,
                'slug' => $this->category->slug,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
            ] : null),
            'latest_release' => $this->whenLoaded('latestRelease', fn () => $this->latestRelease ? (new ReleaseResource($this->latestRelease))->resolve($request) : null),
            'latest_version' => $this->whenLoaded('latestRelease', fn () => $this->latestRelease?->version),
            'compat' => $this->whenLoaded('latestRelease', fn () => $this->latestRelease?->compat ?? []),
            'packages' => $this->whenLoaded('packages', fn () => $this->packages->mapWithKeys(fn ($package) => [$package->kind => $package->name])->all()),
            'price' => (new PriceResource($this->whenLoaded('price', fn () => $this->price, null)))->resolve($request),
            'is_paid' => $this->whenLoaded('price', fn () => $this->price !== null && ! $this->price->isFree(), false),
            'installs_count' => (int) ($this->installs_count ?? 0),
            'published_at' => $this->published_at,
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
        ];
    }
}
