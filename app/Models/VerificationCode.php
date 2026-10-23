<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Short-lived six-digit codes, one live code per subject and purpose (`for`): email
 * verification and password reset. A code of one purpose never consumes as another. A code
 * expires after LIFETIME_MINUTES and stops working after MAX_ATTEMPTS wrong guesses.
 */
class VerificationCode extends EloquentModel
{
    use HasUuid;

    public const FOR_EMAIL_VERIFICATION = 'email_verification';

    public const FOR_PASSWORD_RESET = 'password_reset';

    public const LIFETIME_MINUTES = 30;

    /** Wrong guesses a live code survives; the next correct one is refused too. */
    public const MAX_ATTEMPTS = 5;

    protected $table = 'verification_codes';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['subject_uuid', 'subject_type', 'for', 'code', 'attempts', 'expires_at', 'used_at'];

    protected $casts = ['attempts' => 'integer', 'expires_at' => 'datetime', 'used_at' => 'datetime'];

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

    /**
     * Use the subject's live code for the purpose. A wrong code counts against the live one,
     * which stops working after MAX_ATTEMPTS wrong guesses.
     */
    public static function consume(EloquentModel $subject, string $code, string $for = self::FOR_EMAIL_VERIFICATION): bool
    {
        $record = static::query()
            ->where('subject_uuid', $subject->getKey())
            ->where('for', $for)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', static::MAX_ATTEMPTS)
            ->latest('created_at')
            ->first();

        if (! $record) {
            return false;
        }

        if (! hash_equals((string) $record->code, $code)) {
            $record->increment('attempts');

            return false;
        }

        $record->forceFill(['used_at' => now()])->save();

        return true;
    }
}
