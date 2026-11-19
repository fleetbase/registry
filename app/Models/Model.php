<?php

namespace App\Models;

use App\Traits\Filterable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Base model following `Fleetbase\Models\Model`: UUID primary key, resource/filter
 * class discovery and lookups by uuid or public id. Unlike core-api, soft deletes are
 * opted into per model (only tables with a `deleted_at` column use them).
 */
abstract class Model extends EloquentModel
{
    use Filterable;

    public const UUID_COLUMN = 'uuid';

    public const PUBLIC_ID_COLUMN = 'public_id';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    public function saveInstance(): static
    {
        $this->save();

        return $this;
    }

    public function getResource(): ?string
    {
        return $this->httpResource ?? $this->resource ?? null;
    }

    public function getFilter(): ?string
    {
        return $this->httpFilter ?? $this->filter ?? null;
    }

    /**
     * Find a model by UUID or public id.
     */
    public static function findById(self|string|null $identifier, array $with = [], array $columns = ['*'], bool $withTrashed = false): ?static
    {
        if ($identifier instanceof self) {
            return $identifier;
        }

        if ($identifier === null || $identifier === '') {
            return null;
        }

        $query = static::query()->with($with)->select($columns);

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        $column = Str::isUuid($identifier) ? static::UUID_COLUMN : static::PUBLIC_ID_COLUMN;

        return $query->where($column, $identifier)->first();
    }

    public static function findByIdOrFail(self|string|null $identifier, array $with = [], array $columns = ['*'], bool $withTrashed = false): static
    {
        $result = static::findById($identifier, $with, $columns, $withTrashed);

        if ($result === null) {
            throw (new ModelNotFoundException)->setModel(static::class, [$identifier]);
        }

        return $result;
    }
}
