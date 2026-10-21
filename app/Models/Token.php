<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Personal access token. The plaintext is `flbp_` + 40 random characters; only its
 * SHA-256 hash is stored and the plaintext is returned once at creation.
 */
class Token extends Model
{
    use HasPublicId;
    use HasUuid;

    public const PREFIX_PERSONAL = 'flbp_';

    public const PREFIX_INSTANCE = 'flbi_';

    public const PREFIX_SESSION = 'flbs_';

    public const SCOPE_ALL = '*';

    public const SCOPE_READ = 'read';

    public const SCOPE_PUBLISH = 'publish';

    public const SCOPE_INSTALL = 'install';

    public const SCOPE_INSTANCE_REGISTER = 'instance:register';

    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_READ, self::SCOPE_PUBLISH, self::SCOPE_INSTALL, self::SCOPE_INSTANCE_REGISTER];

    public const MAX_LIFETIME_DAYS = 365;

    protected $table = 'tokens';

    protected string $publicIdType = 'tok';

    protected $fillable = [
        'account_uuid', 'organization_uuid', 'name', 'token_hash', 'prefix', 'last_four', 'scopes',
        'expires_at', 'last_used_at', 'last_used_ip', 'revoked_at',
    ];

    protected $hidden = ['id', 'token_hash'];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Plaintext, only populated on the instance returned by `issue()`. */
    public ?string $plainTextToken = null;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_uuid', 'uuid');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    /**
     * Create a token for an account and return it with `plainTextToken` set.
     */
    public static function issue(Account $account, string $name, array $scopes = [self::SCOPE_ALL], ?\DateTimeInterface $expiresAt = null, ?string $organizationUuid = null, string $prefix = self::PREFIX_PERSONAL): static
    {
        $plain = $prefix.Str::random(40);

        $token = static::create([
            'account_uuid' => $account->uuid,
            'organization_uuid' => $organizationUuid,
            'name' => $name,
            'token_hash' => static::hash($plain),
            'prefix' => $prefix,
            'last_four' => substr($plain, -4),
            'scopes' => array_values(array_unique($scopes)),
            'expires_at' => $expiresAt ?? now()->addDays(static::MAX_LIFETIME_DAYS),
        ]);

        $token->plainTextToken = $plain;

        return $token;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Resolve a plaintext token to its active row, or null.
     */
    public static function findActive(string $plain): ?static
    {
        if ($plain === '') {
            return null;
        }

        return static::query()->active()->where('token_hash', static::hash($plain))->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function can(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array(self::SCOPE_ALL, $scopes, true) || in_array($scope, $scopes, true);
    }

    public function revoke(): static
    {
        if (! $this->isRevoked()) {
            $this->forceFill(['revoked_at' => now()])->save();
        }

        return $this;
    }

    public function touchUsage(?string $ip): void
    {
        $this->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
    }

    /**
     * Masked display form, e.g. `flbp_…a1b2`.
     */
    public function getMaskedAttribute(): string
    {
        return $this->prefix.'…'.$this->last_four;
    }
}
