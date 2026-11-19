<?php

namespace Database\Factories;

use App\Models\Instance;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instance>
 */
class InstanceFactory extends Factory
{
    protected $model = Instance::class;

    public function definition(): array
    {
        return [
            'organization_uuid' => Organization::factory(),
            'name' => fake()->domainWord().' instance',
            'host' => fake()->domainName(),
            'kind' => Instance::KIND_SELF_HOSTED,
            'console_version' => '0.5.0',
            'api_version' => '1.6.0',
            'status' => 'active',
        ];
    }
}
