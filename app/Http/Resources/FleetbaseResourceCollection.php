<?php

namespace App\Http\Resources;

use App\Http\Resources\Json\FleetbasePaginatedResourceResponse;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Arr;

/**
 * Resource collection with per-response exclusions and Fleetbase pagination meta.
 */
class FleetbaseResourceCollection extends ResourceCollection
{
    public $collects;

    public bool $preserveKeys = false;

    protected array $excluded = [];

    public function __construct($resource, $collects = null)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }

    public function without(array|string $keys): static
    {
        $clone = clone $this;
        $clone->excluded = array_values(array_unique(array_merge($this->excluded, (array) $keys)));

        return $clone;
    }

    public function toArray($request): array
    {
        return $this->collection->map(function ($item) use ($request) {
            if ($item instanceof FleetbaseResource) {
                return $this->applyExclusions($item->without($this->excluded)->resolve($request));
            }

            if ($item instanceof JsonResource) {
                return $this->applyExclusions($item->resolve($request));
            }

            if (is_string($this->collects) && class_exists($this->collects)) {
                $resource = new $this->collects($item);

                return $resource instanceof FleetbaseResource
                    ? $resource->without($this->excluded)->resolve($request)
                    : $this->applyExclusions($resource->resolve($request));
            }

            return $this->applyExclusions(is_array($item) ? $item : (array) $item);
        })->all();
    }

    public function toResponse($request)
    {
        return $this->resource instanceof Paginator
            ? (new FleetbasePaginatedResourceResponse($this))->toResponse($request)
            : parent::toResponse($request);
    }

    protected function applyExclusions(array $array): array
    {
        foreach ($this->excluded as $key) {
            Arr::forget($array, $key);
        }

        return $array;
    }
}
