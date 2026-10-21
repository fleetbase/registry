<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Account ↔ organization link with a role. A pivot model so `attach()` still assigns a uuid.
 */
class Membership extends Pivot
{
    use HasUuid;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_PUBLISHER = 'publisher';

    public const ROLE_BILLING = 'billing';

    public const ROLE_MEMBER = 'member';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_PUBLISHER, self::ROLE_BILLING, self::ROLE_MEMBER];

    protected $table = 'memberships';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = ['organization_uuid', 'account_uuid', 'role'];

    protected $hidden = ['id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_uuid', 'uuid');
    }
}
