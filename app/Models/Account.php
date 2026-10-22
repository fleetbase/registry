<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\AccountFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One identity per person. Passwords are bcrypt hashes verified with `Hash::check`,
 * the same scheme core-api's `Auth::checkPassword` uses, so cloud users import as-is.
 */
class Account extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasFactory;
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'accounts';

    protected string $publicIdType = 'acct';

    protected $fillable = [
        'email', 'password', 'name', 'username', 'avatar_url', 'github_id', 'fleetbase_sub',
        'email_verified_at', 'status', 'timezone', 'last_login_ip', 'last_login_at', 'meta',
    ];

    protected $hidden = ['password', 'remember_token', 'id'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'meta' => 'array',
        'password' => 'hashed',
    ];

    /** The token used to authenticate the current request, if any. */
    protected ?Token $currentToken = null;

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class, 'account_uuid', 'uuid');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'account_uuid', 'uuid');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships', 'account_uuid', 'organization_uuid', 'uuid', 'uuid')
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class, 'subject_uuid', 'uuid');
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function markEmailAsVerified(): static
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'status' => $this->status === self::STATUS_PENDING_VERIFICATION ? self::STATUS_ACTIVE : $this->status,
        ])->save();

        return $this;
    }

    public function withCurrentToken(?Token $token): static
    {
        $this->currentToken = $token;

        return $this;
    }

    public function currentToken(): ?Token
    {
        return $this->currentToken;
    }

    public function tokenCan(string $scope): bool
    {
        return $this->currentToken?->can($scope) ?? false;
    }

    /**
     * The account's role in the organization, or null when it is not a member.
     */
    public function roleIn(Organization|string $organization): ?string
    {
        return Membership::query()
            ->where('account_uuid', $this->uuid)
            ->where('organization_uuid', $organization instanceof Organization ? $organization->uuid : $organization)
            ->value('role');
    }

    /**
     * Member of an organization flagged `is_staff` (Fleetbase staff: release review and
     * moderation).
     */
    public function isStaff(): bool
    {
        return $this->organizations()->where('is_staff', true)->exists();
    }

    /**
     * The account's personal organization (created at registration).
     */
    public function personalOrganization(): ?Organization
    {
        return $this->organizations()->where('is_personal', true)->first();
    }
}
