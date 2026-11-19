<?php

namespace App\Http\Resources\Json;

use Illuminate\Http\Resources\Json\PaginatedResourceResponse;

/**
 * Paginated responses carry `data` plus a flat `meta` block, no `links`.
 */
class FleetbasePaginatedResourceResponse extends PaginatedResourceResponse
{
    protected function paginationInformation($request)
    {
        $paginated = $this->resource->resource->toArray();

        return ['meta' => $this->meta($paginated)];
    }

    protected function meta($paginated)
    {
        $metaData = parent::meta($paginated);
        $startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        return [
            'total' => $metaData['total'] ?? null,
            'per_page' => $metaData['per_page'] ?? null,
            'current_page' => $metaData['current_page'] ?? null,
            'last_page' => $metaData['last_page'] ?? null,
            'from' => $metaData['from'] ?? null,
            'to' => $metaData['to'] ?? null,
            'time' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
