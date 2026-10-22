<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\InstanceFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A registered Fleetbase instance (cloud tenant or self-hosted install).
 *
 * Authenticates with an instance token (`flbi_` + 40 random characters) whose SHA-256
 * hash lives in `token_hash`; the plaintext is returned once at registration.
 */
class Instance extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasFactory;
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const KIND_CLOUD = 'cloud';

    public const KIND_SELF_HOSTED = 'self_hosted';

    public const KINDS = [self::KIND_CLOUD, self::KIND_SELF_HOSTED];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

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

    /**
     * Replace the instance token and return the new plaintext (shown once).
     */
    public function issueToken(): string
    {
        $plain = Token::PREFIX_INSTANCE.Str::random(40);

        $this->forceFill(['token_hash' => Token::hash($plain)])->save();

        return $plain;
    }

    /**
     * Resolve a plaintext instance token to its active instance, or null.
     */
    public static function findByToken(string $plain): ?static
    {
        if (! str_starts_with($plain, Token::PREFIX_INSTANCE)) {
            return null;
        }

        return static::query()
            ->where('token_hash', Token::hash($plain))
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function touchLastSeen(?string $ip = null): void
    {
        $meta = $this->meta ?? [];
        if ($ip !== null) {
            $meta['last_seen_ip'] = $ip;
        }

        $this->forceFill(['last_seen_at' => now(), 'meta' => $meta])->saveQuietly();
    }
}
