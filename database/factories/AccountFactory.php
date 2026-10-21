<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'email_verified_at' => now(),
            'status' => Account::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null, 'status' => Account::STATUS_PENDING_VERIFICATION]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Account::STATUS_SUSPENDED]);
    }
}
