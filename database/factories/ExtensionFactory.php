<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Extension>
 */
class ExtensionFactory extends Factory
{
    protected $model = Extension::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'organization_uuid' => Organization::factory(),
            'category_uuid' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'subtitle' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'tags' => fake()->words(3),
            'languages' => ['en'],
            'hosting' => Extension::HOSTING_BOTH,
            'status' => Extension::STATUS_DRAFT,
            'fa_icon' => 'puzzle-piece',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => Extension::STATUS_PUBLISHED, 'published_at' => now()]);
    }

    public function hosting(string $hosting): static
    {
        return $this->state(fn () => ['hosting' => $hosting]);
    }

    /**
     * Attach a free price row.
     */
    public function free(): static
    {
        return $this->has(Price::factory()->free(), 'price');
    }

    /**
     * Attach a one-time price in minor units.
     */
    public function paid(int $amount = 4900, string $currency = 'USD'): static
    {
        return $this->has(Price::factory()->oneTime($amount, $currency), 'price');
    }

    /**
     * Attach npm and composer package names derived from the slug.
     */
    public function withPackages(?string $scope = null): static
    {
        return $this->afterCreating(function (Extension $extension) use ($scope) {
            $scope ??= $extension->organization?->slug ?? 'fleetbase';
            $extension->packages()->createMany([
                ['kind' => ExtensionPackage::KIND_NPM, 'name' => '@'.$scope.'/'.$extension->slug.'-engine'],
                ['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => $scope.'/'.$extension->slug.'-api'],
            ]);
        });
    }

    /**
     * Attach one or more published releases.
     */
    public function withPublishedRelease(string $version = '1.0.0', string $channel = Release::CHANNEL_STABLE): static
    {
        return $this->has(Release::factory()->published()->state(['version' => $version, 'channel' => $channel]), 'releases');
    }
}
