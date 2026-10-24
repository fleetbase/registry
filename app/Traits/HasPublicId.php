<?php

namespace App\Traits;

use App\Support\Utils;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Assigns a prefixed public id (`ext_a1b2c3d4e5`) on create.
 * Ported from `Fleetbase\Traits\HasPublicId`; the model sets `$publicIdType`.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (Utils::isset($model, 'public_id')) {
                return;
            }

            $model->public_id = static::generatePublicId($model->publicIdType ?? null);
        });
    }

    public static function getPublicId(): string
    {
        return Str::lower(Str::random(10));
    }

    public static function generatePublicId(?string $type = null, int $attempt = 0): string
    {
        if ($attempt > 10) {
            throw new \RuntimeException('Failed to generate unique public_id after 10 attempts.');
        }

        $type ??= static::getPublicIdType() ?? strtolower(Utils::classBasename(static::class));
        $publicId = $type.'_'.static::getPublicId();

        $query = (new static)->newQuery()->where('public_id', $publicId);
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withTrashed();
        }

        if ($query->exists()) {
            usleep((2 ** $attempt) * 1000);

            return static::generatePublicId($type, $attempt + 1);
        }

        return $publicId;
    }

    public static function getPublicIdType(): ?string
    {
        return (new static)->publicIdType ?? null;
    }
}
