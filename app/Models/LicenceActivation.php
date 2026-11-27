<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenceActivation extends Model
{
    use HasUuid;

    protected $table = 'licence_activations';

    protected $fillable = ['licence_uuid', 'instance_uuid', 'release_uuid', 'activated_at', 'deactivated_at'];

    protected $hidden = ['id'];

    protected $casts = ['activated_at' => 'datetime', 'deactivated_at' => 'datetime'];

    public function licence(): BelongsTo
    {
        return $this->belongsTo(Licence::class, 'licence_uuid', 'uuid');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_uuid', 'uuid');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_uuid', 'uuid');
    }
}
