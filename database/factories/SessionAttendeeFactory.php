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
            'late_cancelled' => false,
            'client_note' => null,
            'price_override' => null,
            'subtotal' => 0,
            'gst_amount' => 0,
            'total' => 0,
        ];
    }

    /** Charged as booked, but not in the room. */
    public function lateCancelled(string $note = 'Cancelled the morning of'): static
    {
        return $this->state(['attended' => true, 'late_cancelled' => true, 'client_note' => $note]);
    }
}
