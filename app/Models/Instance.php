<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\InstanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A registered Fleetbase instance (cloud tenant or self-hosted install).
 */
class Instance extends Model
{
    use HasFactory;
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const KIND_CLOUD = 'cloud';

    public const KIND_SELF_HOSTED = 'self_hosted';

    public const KINDS = [self::KIND_CLOUD, self::KIND_SELF_HOSTED];

    protected $table = 'instances';

    protected string $publicIdType = 'inst';

    protected $fillable = [
        'organization_uuid', 'registered_by_account_uuid', 'name', 'host', 'kind', 'console_version',
        'api_version', 'token_hash', 'status', 'last_seen_at', 'meta',
    ];

    protected $hidden = ['id', 'token_hash'];

    protected $casts = ['last_seen_at' => 'datetime', 'meta' => 'array'];

    protected static function newFactory(): InstanceFactory
    {
        return InstanceFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'registered_by_account_uuid', 'uuid');
    }

    public function installs(): HasMany
    {
        return $this->hasMany(Install::class, 'instance_uuid', 'uuid');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenceActivation::class, 'instance_uuid', 'uuid');
    }
}
