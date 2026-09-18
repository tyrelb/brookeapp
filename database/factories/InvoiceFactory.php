<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /** Monotonic across a run, so it can never collide with unique(user_id, sequence). */
    private static int $sequence = 0;

    public function definition(): array
    {
        $sequence = ++self::$sequence;
        $subtotal = 400.00;
        $gst = 20.00;

        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'sequence' => $sequence,
            'number' => 'INV-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'lines' => [[
                'description' => 'Fitness Wallet top-up',
                'quantity' => null,
                'unit_price' => null,
                'amount' => $subtotal,
            ]],
            'subtotal' => $subtotal,
            'gst_amount' => $gst,
            'gst_rate' => 5.00,
            'total' => $subtotal + $gst,
            'issued_on' => today()->toDateString(),
            'due_on' => today()->addDays(7)->toDateString(),
            'message' => null,
            'sent_at' => now(),
            'voided_at' => null,
        ];
    }

    public function unsent(): static
    {
        return $this->state(fn () => ['sent_at' => null]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'issued_on' => today()->subDays(30)->toDateString(),
            'due_on' => today()->subDays(23)->toDateString(),
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => ['voided_at' => now()]);
    }
}
