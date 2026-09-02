<?php

namespace Database\Factories;

use App\Enums\SessionStatus;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSession>
 */
class TrainingSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'service_id' => fn (array $attributes) => Service::factory()->create(['user_id' => $attributes['user_id']])->id,
            'starts_at' => now()->startOfHour(),
            'duration_minutes' => 60,
            'status' => SessionStatus::Scheduled,
            'completed_at' => null,
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => SessionStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
