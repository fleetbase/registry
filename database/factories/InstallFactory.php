<?php

namespace Database\Factories;

use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Install>
 */
class InstallFactory extends Factory
{
    protected $model = Install::class;

    public function definition(): array
    {
        return [
            'instance_uuid' => Instance::factory(),
            'extension_uuid' => Extension::factory(),
            'status' => Install::STATUS_INSTALLED,
            'installed_by' => 'console',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => Install::STATUS_FAILED, 'error' => 'composer require failed']);
    }

    public function uninstalled(): static
    {
        return $this->state(fn () => ['status' => Install::STATUS_UNINSTALLED]);
    }
}
