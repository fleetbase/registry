<?php

namespace App\Http\Resources;

use App\Support\Http;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Base JSON resource following `Fleetbase\Http\Resources\FleetbaseResource`:
 * public ids on public routes, internal ids on internal routes, `without()` exclusions.
 */
class FleetbaseResource extends JsonResource
{
    protected array $excluded = [];

    public function toArray($request)
    {
        return $this->filterExcluded(parent::toArray($request));
    }

    public static function collection($resource)
    {
        return tap(new FleetbaseResourceCollection($resource, static::class), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    public function isEmpty(): bool
    {
        return is_null($this->resource) || is_null($this->resource->resource ?? $this->resource);
    }

    /**
     * Every `*_uuid` attribute, included only on internal requests.
     */
    public function getInternalIds(): array
    {
        $internalIds = [];

        foreach ($this->resource?->getAttributes() ?? [] as $key => $value) {
            if (Str::endsWith($key, '_uuid')) {
                $internalIds[$key] = $this->when(Http::isInternalRequest(), $value);
            }
        }

        return $internalIds;
    }

    public function without(array|string $keys): static
    {
        $clone = clone $this;
        $clone->excluded = array_merge($this->excluded, (array) $keys);

        return $clone;
    }

    protected function filterExcluded(array $data): array
    {
        foreach ($this->excluded as $key) {
            Arr::forget($data, $key);
        }

        return $data;
    }
}
