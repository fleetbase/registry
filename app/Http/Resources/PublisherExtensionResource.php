<?php

namespace App\Http\Resources;

use App\Models\Release;
use App\Releases\ListingReadiness;
use App\Support\Http;
use Illuminate\Support\Facades\Date;

/**
 * A listing as its publisher sees it, with what it still needs before its first release
 * can be submitted.
 */
class PublisherExtensionResource extends FleetbaseResource
{
    protected const UNSUBMITTED = [Release::STATUS_UPLOADED, Release::STATUS_CHECKING, Release::STATUS_FAILED_CHECKS];

    public function toArray($request): array
    {
        $missing = app(ListingReadiness::class)->errors($this->resource);

        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'slug' => $this->slug,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'promotional_text' => $this->promotional_text,
            'status' => $this->status,
            'hosting' => $this->hosting,
            'organization' => $this->whenLoaded('organization', fn () => ['id' => $this->organization->public_id, 'slug' => $this->organization->slug, 'name' => $this->organization->name]),
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->public_id, 'slug' => $this->category->slug, 'name' => $this->category->name] : null),
            'tags' => $this->tags ?? [],
            'languages' => $this->languages ?? [],
            'website_url' => $this->website_url,
            'repo_url' => $this->repo_url,
            'support_url' => $this->support_url,
            'privacy_url' => $this->privacy_url,
            'tos_url' => $this->tos_url,
            'copyright' => $this->copyright,
            'fa_icon' => $this->fa_icon,
            'icon_url' => $this->whenLoaded('icon', fn () => $this->icon?->url, null),
            'screenshots' => $this->whenLoaded('screenshots', fn () => ExtensionResource::screenshotList($this->resource), []),
            'packages' => $this->whenLoaded('packages', fn () => $this->packages->map(fn ($package) => ['kind' => $package->kind, 'name' => $package->name])->values()->all()),
            'price' => $this->whenLoaded('price', fn () => (new PriceResource($this->price))->resolve($request)),
            'readiness' => ['ready' => $missing === [], 'missing' => $missing],
            'releases' => $this->whenLoaded('releases', fn () => $this->releases->map(fn ($release) => [
                'id' => $release->public_id,
                'version' => $release->version,
                'channel' => $release->channel,
                'status' => $release->status,
                // A withdrawn release keeps its old submission time in meta; it is not submitted now.
                'submitted_at' => in_array($release->status, self::UNSUBMITTED, true) ? null : $release->metaValue('submission.submitted_at'),
                // Set when the releases were loaded with ExtensionController::withDecidedAt.
                'decided_at' => self::decidedAt($release->getAttributes()['decided_at'] ?? null),
                'published_at' => $release->published_at,
                'created_at' => $release->created_at,
            ])->values()->all()),
            'suspension' => $this->meta['suspension'] ?? null,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected static function decidedAt(mixed $value): ?string
    {
        return $value === null ? null : Date::parse($value)->toJSON();
    }
}
