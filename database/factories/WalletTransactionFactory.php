<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Client;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => fn (array $attributes) => Client::factory()->create(['user_id' => $attributes['user_id']])->id,
            'type' => TransactionType::Payment,
            'amount' => 100.00,
            'subtotal' => 95.24,
            'gst_amount' => 4.76,
            'payment_method' => PaymentMethod::Cash,
            'reference' => null,
            'transacted_on' => now()->toDateString(),
            'billing_period' => null,
            'training_session_id' => null,
            'description' => 'Deposit',
            'voided_at' => null,
        ];
    }
}
