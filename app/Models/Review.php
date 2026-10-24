<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Review timeline entry for a release: one row per status transition (see
 * `App\Releases\ReleaseStateMachine`), carrying the history and the rejection reasons.
 */
class Review extends Model
{
    use HasUuid;

    public const ACTION_UPLOAD = 'upload';

    public const ACTION_CHECK = 'check';

    public const ACTION_PASS_CHECKS = 'pass_checks';

    public const ACTION_FAIL_CHECKS = 'fail_checks';

    public const ACTION_SUBMIT = 'submit';

    public const ACTION_CLAIM = 'claim';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_WITHDRAW = 'withdraw';

    public const ACTION_PUBLISH = 'publish';

    public const ACTION_YANK = 'yank';

    public const ACTIONS = [
        self::ACTION_UPLOAD, self::ACTION_CHECK, self::ACTION_PASS_CHECKS, self::ACTION_FAIL_CHECKS,
        self::ACTION_SUBMIT, self::ACTION_CLAIM, self::ACTION_APPROVE, self::ACTION_REJECT,
        self::ACTION_WITHDRAW, self::ACTION_PUBLISH, self::ACTION_YANK,
    ];

    public const ACTOR_PUBLISHER = 'publisher';

    public const ACTOR_STAFF = 'staff';

    public const ACTOR_SYSTEM = 'system';

    protected $table = 'reviews';

    protected $fillable = ['release_uuid', 'reviewer_account_uuid', 'actor', 'action', 'from_status', 'to_status', 'reason', 'items'];

    protected $hidden = ['id'];

    protected $casts = ['items' => 'array'];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_uuid', 'uuid');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'reviewer_account_uuid', 'uuid');
    }
}
