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

    /**
     * The instance's registered host name, lowercased, whether `host` was stored as a URL or a
     * bare host (with or without a port).
     */
    public function hostname(): ?string
    {
        $host = trim((string) $this->host);
        if ($host === '') {
            return null;
        }

        $name = parse_url(str_contains($host, '://') ? $host : 'http://'.$host, PHP_URL_HOST);

        return is_string($name) && $name !== '' ? strtolower($name) : null;
    }

    /**
     * A URL on this instance's registered host or its parent domain: an instance registered as
     * `api.acme.com` may send buyers back to `acme.com` or `console.acme.com`. IP addresses and
     * single-label hosts (`localhost`) only match themselves.
     */
    public function ownsUrl(string $url): bool
    {
        $registered = $this->hostname();
        $host = parse_url($url, PHP_URL_HOST);
        if ($registered === null || ! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);
        if ($host === $registered) {
            return true;
        }

        $labels = explode('.', $registered);
        if (filter_var($registered, FILTER_VALIDATE_IP) || count($labels) < 2) {
            return false;
        }

        $base = count($labels) > 2 ? implode('.', array_slice($labels, 1)) : $registered;

        return $host === $base || str_ends_with($host, '.'.$base);
    }

    /**
     * The active instance of an organization registered from the same host (scheme, case and a
     * trailing slash ignored), most recently seen first. Null when the host is empty.
     */
    public static function findByHost(string $organizationUuid, ?string $host): ?static
    {
        $host = static::normalizeHost($host);
        if ($host === null) {
            return null;
        }

        return static::query()
            ->where('organization_uuid', $organizationUuid)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('host')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (Instance $instance) => static::normalizeHost($instance->host) === $host);
    }

    /**
     * `HTTPS://Fleet.Acme.test/` and `fleet.acme.test` are the same host.
     */
    public static function normalizeHost(?string $host): ?string
    {
        $host = strtolower(trim((string) $host));
        $host = rtrim((string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host), '/');

        return $host !== '' ? $host : null;
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
