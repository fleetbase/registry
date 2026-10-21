<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit log for admin actions, token use and webhook receipts.
 */
class Event extends EloquentModel
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'events';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['subject_type', 'subject_uuid', 'actor_type', 'actor_uuid', 'kind', 'payload', 'ip_address'];

    protected $hidden = ['id'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_uuid', 'uuid');
    }

    public function actor(): MorphTo
    {
        return $this->morphTo('actor', 'actor_type', 'actor_uuid', 'uuid');
    }

    /**
     * Record an event. Never throws; auditing must not break the request.
     */
    public static function record(string $kind, ?EloquentModel $subject = null, ?EloquentModel $actor = null, array $payload = [], ?string $ip = null): ?static
    {
        try {
            return static::create([
                'kind' => $kind,
                'subject_type' => $subject?->getMorphClass(),
                'subject_uuid' => $subject?->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'actor_uuid' => $actor?->getKey(),
                'payload' => $payload,
                'ip_address' => $ip ?? request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
