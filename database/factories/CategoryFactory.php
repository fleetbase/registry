<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'internal_id' => 'FLB'.Str::upper(Str::random(6)),
            'description' => fake()->sentence(),
            'icon' => 'cube',
            'tags' => fake()->words(3),
            'sort' => fake()->numberBetween(0, 50),
        ];
    }
}
