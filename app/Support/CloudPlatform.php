<?php

namespace App\Support;

use App\Models\Instance;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Fleetbase Cloud as a client of the registry: one instance per cloud deployment (kind `cloud`,
 * `meta.platform`), owned by the organization named in `registry.cloud.organization`, that acts
 * for each cloud company's organization (its tenant) through `X-Registry-Tenant`.
 *
 * The cloud API registers it with a signed request (`PlatformSignature`); `registry:import`
 * creates it first as a placeholder so imported installs have somewhere to live, and the first
 * registration rotates onto it.
 */
class CloudPlatform
{
    /** Company uuid of the cloud tenant a request acts for. */
    public const TENANT_HEADER = 'X-Registry-Tenant';

    /** Company name, URL-encoded; names the organization created for a tenant seen for the first time. */
    public const TENANT_NAME_HEADER = 'X-Registry-Tenant-Name';

    /**
     * The organization that owns the cloud instance, created on first use.
     */
    public static function organization(): Organization
    {
        $slug = Str::slug((string) config('registry.cloud.organization', 'fleetbase')) ?: 'fleetbase';

        return Organization::withTrashed()->where('slug', $slug)->first()
            ?? Organization::create(['slug' => $slug, 'name' => 'Fleetbase Cloud', 'is_personal' => false, 'meta' => ['source' => 'cloud-platform']]);
    }

    /**
     * The active cloud instance for a host: the one registered from that host (D17), else the
     * placeholder `registry:import` left that never got a token, else (`$anyHost`, for the
     * importer when it is given no host) the most recently seen cloud instance of the
     * organization. Null when there is none.
     */
    public static function instance(Organization $organization, ?string $host, bool $anyHost = true): ?Instance
    {
        $instances = Instance::query()
            ->where('organization_uuid', $organization->uuid)
            ->where('kind', Instance::KIND_CLOUD)
            ->where('status', Instance::STATUS_ACTIVE)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Instance $instance) => $instance->isPlatform());

        $normalized = Instance::normalizeHost($host);

        return ($normalized !== null ? $instances->first(fn (Instance $i) => Instance::normalizeHost($i->host) === $normalized) : null)
            ?? $instances->first(fn (Instance $i) => $i->token_hash === null)
            ?? ($anyHost && $normalized === null ? $instances->first() : null);
    }

    /**
     * The organization a cloud company acts as: the one imported from that company or created
     * the first time it is seen. Null for a value that is not a uuid.
     */
    public static function tenant(string $companyUuid, ?string $name = null): ?Organization
    {
        $companyUuid = strtolower(trim($companyUuid));
        if (! Str::isUuid($companyUuid)) {
            return null;
        }

        $organization = Organization::query()->where('source_uuid', $companyUuid)->first();
        if ($organization) {
            return $organization;
        }

        $name = trim((string) $name) !== '' ? Str::limit(trim((string) $name), 191, '') : 'Company '.Str::limit($companyUuid, 8, '');

        $organization = (new Organization)->forceFill([
            'slug' => static::uniqueSlug($name),
            'name' => $name,
            'is_personal' => false,
            // Same key the importer gives a company's organization, so a later import finds it.
            'source_uuid' => $companyUuid,
            'meta' => ['source' => 'cloud', 'company_uuid' => $companyUuid],
        ]);
        $organization->save();

        return $organization;
    }

    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        for ($n = 2; Organization::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
