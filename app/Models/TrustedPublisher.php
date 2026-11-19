<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GitHub Actions workflow allowed to publish releases of one extension without a stored
 * secret: its OIDC token is exchanged at `POST /v1/auth/oidc/github` for a short-lived `flbt_`
 * token limited to the extension (see `App\TrustedPublishing\TokenExchange`).
 *
 * A token matches when its `repository` is `repository`, its `job_workflow_ref` is
 * `{repository}/.github/workflows/{workflow}@{ref}`, its `ref` matches one of `refs` (glob
 * patterns such as `refs/tags/v*`), and, when `environment` is set, its `environment` is that.
 * `repository_id` and `repository_owner_id` are pinned on first use.
 */
class TrustedPublisher extends Model
{
    use HasPublicId;
    use HasUuid;

    public const PROVIDER_GITHUB = 'github';

    protected $table = 'trusted_publishers';

    protected string $publicIdType = 'tp';

    protected $fillable = [
        'extension_uuid', 'created_by_account_uuid', 'provider', 'repository', 'workflow', 'environment', 'refs',
        'repository_id', 'repository_owner_id', 'last_used_at', 'meta',
    ];

    protected $hidden = ['id'];

    protected $casts = ['refs' => 'array', 'last_used_at' => 'datetime', 'meta' => 'array'];

    protected static function booted(): void
    {
        static::saving(function (TrustedPublisher $publisher) {
            $publisher->repository = strtolower(trim((string) $publisher->repository));
            $publisher->workflow = trim((string) $publisher->workflow);
        });
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by_account_uuid', 'uuid');
    }

    public function owner(): string
    {
        return (string) strstr($this->repository, '/', true);
    }

    /**
     * Whether a ref (`refs/tags/v1.2.0`) matches one of the allowed patterns. `*` matches
     * within a path segment run, as in `fnmatch` without FNM_PATHNAME.
     */
    public function allowsRef(string $ref): bool
    {
        foreach ((array) $this->refs as $pattern) {
            if (is_string($pattern) && $pattern !== '' && fnmatch($pattern, $ref)) {
                return true;
            }
        }

        return false;
    }
}
