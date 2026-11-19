<?php

namespace App\Actions;

use App\Exceptions\LicenceRequiredException;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

/**
 * Ensures an instance holds an active seat on one of its organization's licences for an
 * extension, creating the automatic free licence on first install of a free extension.
 *
 * Seat = instance (D4). Re-activating an instance that already holds a seat only moves
 * the activation to the new release. The cloud instance acting for a company uses that
 * company's organization's licences (`Instance::licensingOrganizationUuid()`), so each cloud
 * company holds its own seat on the one cloud instance.
 */
class ActivateLicence
{
    public function __invoke(Instance $instance, Extension $extension, ?Release $release = null): LicenceActivation
    {
        return DB::transaction(function () use ($instance, $extension, $release) {
            $licences = Licence::query()
                ->active()
                ->where('extension_uuid', $extension->uuid)
                ->where('organization_uuid', $instance->licensingOrganizationUuid())
                ->orderByRaw('case when kind = ? then 0 else 1 end', [Licence::KIND_PAID])
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $existing = LicenceActivation::query()
                ->whereIn('licence_uuid', $licences->pluck('uuid'))
                ->where('instance_uuid', $instance->uuid)
                ->whereNull('deactivated_at')
                ->first();

            if ($existing) {
                if ($release && $existing->release_uuid !== $release->uuid) {
                    $existing->forceFill(['release_uuid' => $release->uuid])->save();
                }

                return $existing->setRelation('licence', $licences->firstWhere('uuid', $existing->licence_uuid));
            }

            if ($licences->isEmpty()) {
                $licences = collect([$this->freeLicence($instance, $extension)]);
            }

            /** @var Licence|null $licence */
            $licence = $licences->first(fn (Licence $licence) => $licence->hasFreeSeat());
            if (! $licence) {
                $seats = (int) $licences->sum('max_activations');

                throw new LicenceRequiredException("All {$seats} seats for '{$extension->slug}' are in use. Uninstall it from another instance first.");
            }

            $activation = LicenceActivation::create([
                'licence_uuid' => $licence->uuid,
                'instance_uuid' => $instance->uuid,
                'release_uuid' => $release?->uuid,
                'activated_at' => now(),
            ]);

            return $activation->setRelation('licence', $licence);
        });
    }

    /**
     * Free extensions get one unlimited licence per organization on first install.
     * Paid extensions, and free ones whose licence staff revoked, are refused.
     */
    protected function freeLicence(Instance $instance, Extension $extension): Licence
    {
        $extension->loadMissing('price');

        if ($extension->isPaid()) {
            throw new LicenceRequiredException("'{$extension->slug}' is a paid extension and your organization does not hold an active licence for it.");
        }

        $revoked = Licence::query()
            ->where('extension_uuid', $extension->uuid)
            ->where('organization_uuid', $instance->licensingOrganizationUuid())
            ->where('status', Licence::STATUS_REVOKED)
            ->exists();

        if ($revoked) {
            throw new LicenceRequiredException("Your organization's licence for '{$extension->slug}' has been revoked.");
        }

        return Licence::create([
            'extension_uuid' => $extension->uuid,
            'organization_uuid' => $instance->licensingOrganizationUuid(),
            'kind' => Licence::KIND_FREE,
            'max_activations' => null,
            'status' => Licence::STATUS_ACTIVE,
        ]);
    }
}
