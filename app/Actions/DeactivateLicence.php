<?php

namespace App\Actions;

use App\Models\Extension;
use App\Models\Instance;
use App\Models\LicenceActivation;

/**
 * Releases every seat an instance holds on licences for an extension.
 * Returns the number of activations closed.
 */
class DeactivateLicence
{
    public function __invoke(Instance $instance, Extension $extension): int
    {
        return LicenceActivation::query()
            ->where('instance_uuid', $instance->uuid)
            ->whereNull('deactivated_at')
            ->whereHas('licence', fn ($q) => $q->where('extension_uuid', $extension->uuid))
            ->update(['deactivated_at' => now(), 'updated_at' => now()]);
    }
}
