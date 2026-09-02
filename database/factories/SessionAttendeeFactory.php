<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionAttendee>
 */
class SessionAttendeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'training_session_id' => TrainingSession::factory(),
            'client_id' => Client::factory(),
            'attended' => true,
            'price_override' => null,
            'subtotal' => 0,
            'gst_amount' => 0,
            'total' => 0,
        ];
    }
}
