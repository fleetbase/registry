<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory;
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'categories';

    protected string $publicIdType = 'cat';

    protected $fillable = ['slug', 'name', 'internal_id', 'description', 'icon', 'tags', 'sort'];

    protected $hidden = ['id'];

    protected $casts = ['tags' => 'array', 'sort' => 'integer'];

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class, 'category_uuid', 'uuid');
    }
}
