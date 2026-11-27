<?php

namespace App\Http\Resources;

use App\Models\Extension;
use App\Models\ExtensionPackage;

/**
 * Full listing: everything in the summary plus description, links, screenshots, versions and the
 * other extensions the latest release needs (`requires_extensions`).
 */
class ExtensionResource extends ExtensionSummaryResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            'promotional_text' => $this->promotional_text,
            'languages' => $this->languages ?? [],
            'website_url' => $this->website_url,
            'repo_url' => $this->repo_url,
            'support_url' => $this->support_url,
            'privacy_url' => $this->privacy_url,
            'tos_url' => $this->tos_url,
            'copyright' => $this->copyright,
            'requires_extensions' => $this->whenLoaded('latestRelease', fn () => static::requiredExtensions($this->latestRelease?->compat['extensions'] ?? [])),
            'screenshots' => $this->whenLoaded('screenshots', fn () => static::screenshotList($this->resource)),
            'versions' => $this->whenLoaded('publishedReleases', fn () => $this->publishedReleases->map(fn ($release) => [
                'id' => $release->public_id,
                'version' => $release->version,
                'channel' => $release->channel,
                'published_at' => $release->published_at,
                'dist' => $release->relationLoaded('engineDist') ? ReleaseResource::dist($release) : null,
            ])->all()),
            'versions_count' => $this->whenCounted('publishedReleases'),
            'publisher' => $this->whenLoaded('organization', fn () => (new PublisherResource($this->organization))->resolve($request)),
        ]);
    }

    /**
     * `compat.extensions` as a list: `[{package, range, name, slug}]`. `name` and `slug` come from the
     * published listing that ships that console package, when the catalog has one; null otherwise.
     *
     * @param  array<string, string>  $required
     * @return list<array{package: string, range: string, name: ?string, slug: ?string}>
     */
    public static function requiredExtensions(mixed $required): array
    {
        if (! is_array($required) || $required === []) {
            return [];
        }

        $listings = ExtensionPackage::query()
            ->where('kind', ExtensionPackage::KIND_NPM)
            ->whereIn('name', array_keys($required))
            ->whereHas('extension', fn ($q) => $q->published()->publiclyVisible())
            ->with('extension:uuid,slug,name')
            ->get()
            ->keyBy('name');

        return collect($required)->map(fn ($range, $package) => [
            'package' => (string) $package,
            'range' => (string) $range,
            'name' => $listings->get($package)?->extension?->name,
            'slug' => $listings->get($package)?->extension?->slug,
        ])->values()->all();
    }

    /**
     * The listing's loaded screenshots in display order, as the catalog, the listing editor
     * and the review console show them. `order` is the position, from 0.
     */
    public static function screenshotList(Extension $extension): array
    {
        return $extension->orderedScreenshots()->map(fn ($file, int $position) => [
            'id' => $file->public_id,
            'url' => $file->url,
            'caption' => $file->meta['caption'] ?? null,
            'order' => $position,
            'name' => $file->name,
            'mime' => $file->mime,
        ])->all();
    }
}
