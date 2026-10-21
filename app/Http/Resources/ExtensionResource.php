<?php

namespace App\Http\Resources;

/**
 * Full listing: everything in the summary plus description, links, screenshots and versions.
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
            'screenshots' => $this->whenLoaded('screenshots', fn () => $this->screenshots->map(fn ($file) => [
                'id' => $file->public_id,
                'url' => $file->url,
                'name' => $file->name,
                'mime' => $file->mime,
            ])->all()),
            'versions' => $this->whenLoaded('publishedReleases', fn () => $this->publishedReleases->map(fn ($release) => [
                'id' => $release->public_id,
                'version' => $release->version,
                'channel' => $release->channel,
                'published_at' => $release->published_at,
            ])->all()),
            'versions_count' => $this->whenCounted('publishedReleases'),
            'publisher' => $this->whenLoaded('organization', fn () => (new PublisherResource($this->organization))->resolve($request)),
        ]);
    }
}
