<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Short-lived numeric codes for email verification (and later password reset).
 */
class VerificationCode extends EloquentModel
{
    use HasUuid;

    public const FOR_EMAIL_VERIFICATION = 'email_verification';

    public const LIFETIME_MINUTES = 30;

    protected $table = 'verification_codes';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['subject_uuid', 'subject_type', 'for', 'code', 'expires_at', 'used_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_uuid', 'uuid');
    }

    public static function generateFor(EloquentModel $subject, string $for = self::FOR_EMAIL_VERIFICATION): static
    {
        // Only one live code per subject and purpose.
        static::query()->where('subject_uuid', $subject->getKey())->where('for', $for)->whereNull('used_at')->delete();

        return static::create([
            'subject_uuid' => $subject->getKey(),
            'subject_type' => $subject::class,
            'for' => $for,
            'code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'expires_at' => now()->addMinutes(static::LIFETIME_MINUTES),
        ]);
    }

    public static function consume(EloquentModel $subject, string $code, string $for = self::FOR_EMAIL_VERIFICATION): bool
    {
        $record = static::query()
            ->where('subject_uuid', $subject->getKey())
            ->where('for', $for)
            ->where('code', $code)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            return false;
        }

        $record->forceFill(['used_at' => now()])->save();

        return true;
    }
}
