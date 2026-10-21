<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * npm or Composer package name owned by a listing; unique per registry.
 */
class ExtensionPackage extends Model
{
    use HasUuid;

    public const KIND_NPM = 'npm';

    public const KIND_COMPOSER = 'composer';

    public const KINDS = [self::KIND_NPM, self::KIND_COMPOSER];

    protected $table = 'extension_packages';

    protected $fillable = ['extension_uuid', 'kind', 'name'];

    protected $hidden = ['id'];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }
}
