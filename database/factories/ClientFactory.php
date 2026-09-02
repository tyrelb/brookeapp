<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'status' => ClientStatus::Active,
            'notes' => null,
            'started_at' => now()->subMonths(fake()->numberBetween(1, 12))->toDateString(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ClientStatus::Inactive]);
    }
}
