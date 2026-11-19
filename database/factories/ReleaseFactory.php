<?php

namespace Database\Factories;

use App\Models\Extension;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        $version = fake()->numberBetween(0, 3).'.'.fake()->numberBetween(0, 9).'.'.fake()->numberBetween(0, 9);

        return [
            'extension_uuid' => Extension::factory(),
            'version' => $version,
            'channel' => Release::CHANNEL_STABLE,
            'status' => Release::STATUS_UPLOADED,
            'manifest' => ['name' => 'example', 'version' => $version],
            'compat' => ['console' => '>=0.5.0', 'api' => '>=1.6.0'],
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'checksum' => 'sha256:'.fake()->sha256(),
            'notes' => fake()->sentence(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => Release::STATUS_PUBLISHED, 'published_at' => now()]);
    }

    public function beta(): static
    {
        return $this->state(fn () => ['channel' => Release::CHANNEL_BETA]);
    }

    public function yanked(): static
    {
        return $this->state(fn () => ['status' => Release::STATUS_YANKED, 'published_at' => now()->subDay(), 'yanked_at' => now()]);
    }
}
