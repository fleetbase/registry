<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-use code an organization admin mints (account token with `instance:register`)
 * and pastes into an instance, which exchanges it for an instance token at
 * `POST /v1/instances/register`. Stored as SHA-256; plaintext returned once.
 */
class InstanceRegistrationCode extends Model
{
    use HasUuid;

    public const LIFETIME_MINUTES = 15;

    /** Crockford base32 without I, L, O, U: unambiguous when read aloud or retyped. */
    protected const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    protected $table = 'instance_registration_codes';

    protected $fillable = ['organization_uuid', 'account_uuid', 'code_hash', 'expires_at', 'used_at', 'instance_uuid', 'created_ip'];

    protected $hidden = ['id', 'code_hash'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    /** Plaintext, only populated on the instance returned by `mint()`. */
    public ?string $plainTextCode = null;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_uuid', 'uuid');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_uuid', 'uuid');
    }

    /**
     * Mint a code (`XXXX-XXXX-XXXX-XXXX`, 80 bits) for an organization.
     */
    public static function mint(Organization $organization, Account $account, ?string $ip = null): static
    {
        $plain = static::generatePlain();

        $code = static::create([
            'organization_uuid' => $organization->uuid,
            'account_uuid' => $account->uuid,
            'code_hash' => static::hash($plain),
            'expires_at' => now()->addMinutes(static::LIFETIME_MINUTES),
            'created_ip' => $ip,
        ]);

        $code->plainTextCode = $plain;

        return $code;
    }

    public static function generatePlain(): string
    {
        $chars = '';
        for ($i = 0; $i < 16; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($chars, 4));
    }

    /**
     * Codes are compared case-insensitively and without separators or whitespace.
     */
    public static function normalize(string $plain): string
    {
        $chars = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $plain));

        return implode('-', str_split($chars, 4));
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', static::normalize($plain));
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /**
     * Atomically mark the code behind `$plain` used and return it, or null when the
     * code is unknown, expired or already used. Safe against concurrent redemption.
     */
    public static function redeem(string $plain): ?static
    {
        if (trim($plain) === '') {
            return null;
        }

        $code = static::query()->usable()->where('code_hash', static::hash($plain))->first();
        if (! $code) {
            return null;
        }

        $claimed = static::query()->usable()->whereKey($code->getKey())->update(['used_at' => now(), 'updated_at' => now()]);

        return $claimed === 1 ? $code->refresh() : null;
    }
}
