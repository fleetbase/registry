<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Review timeline entry for a release; the history and the rejection reasons.
 */
class Review extends Model
{
    use HasUuid;

    public const ACTION_SUBMIT = 'submit';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_WITHDRAW = 'withdraw';

    public const ACTION_PUBLISH = 'publish';

    public const ACTION_YANK = 'yank';

    public const ACTIONS = [
        self::ACTION_SUBMIT, self::ACTION_APPROVE, self::ACTION_REJECT,
        self::ACTION_WITHDRAW, self::ACTION_PUBLISH, self::ACTION_YANK,
    ];

    protected $table = 'reviews';

    protected $fillable = ['release_uuid', 'reviewer_account_uuid', 'action', 'reason', 'items'];

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
