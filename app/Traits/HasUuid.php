<?php

namespace App\Traits;

use App\Support\Utils;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Assigns a UUID primary key on create. Ported from `Fleetbase\Traits\HasUuid`.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (Utils::notEmpty($model->uuid)) {
                return;
            }

            if (isset($model->uuidColumn)) {
                foreach ((array) $model->uuidColumn as $column) {
                    $model->{$column} = static::generateUuid($column);
                }

                return;
            }

            $model->uuid = static::generateUuid();
        });
    }

    public static function generateUuid(string $column = 'uuid'): string
    {
        $uuid = (string) Str::uuid();
        $query = (new static)->newQuery()->where($column, $uuid);

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        return $query->exists() ? static::generateUuid($column) : $uuid;
    }
}
