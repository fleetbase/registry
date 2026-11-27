<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * `php artisan registry:staff fleetbase` flags an organization as Fleetbase staff, which
 * lets its extensions own the reserved `@fleetbase/*` and `fleetbase/*` package names.
 */
class ManageStaffOrganization extends Command
{
    protected $signature = 'registry:staff
        {organization : Organization slug or public id}
        {--revoke : Remove the staff flag instead}';

    protected $description = 'Grant or revoke the Fleetbase staff flag on an organization';

    public function handle(): int
    {
        $identifier = (string) $this->argument('organization');
        $organization = Organization::query()->where('slug', $identifier)->orWhere('public_id', $identifier)->first();

        if ($organization === null) {
            $this->error("No organization matches [{$identifier}].");

            return self::FAILURE;
        }

        $organization->forceFill(['is_staff' => ! $this->option('revoke')])->save();

        $this->info($organization->is_staff
            ? "{$organization->name} ({$organization->slug}) is now a staff organization."
            : "{$organization->name} ({$organization->slug}) is no longer a staff organization.");

        return self::SUCCESS;
    }
}
