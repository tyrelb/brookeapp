<?php

namespace Database\Factories;

use App\Enums\PlanType;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Pay-as-you-go',
            'type' => PlanType::Wallet,
            'monthly_fee' => null,
            'billing_day' => null,
            'description' => null,
            'active' => true,
        ];
    }

    public function monthly(float $fee = 300.00, int $billingDay = 1): static
    {
        return $this->state(fn () => [
            'name' => 'Monthly Unlimited',
            'type' => PlanType::Monthly,
            'monthly_fee' => $fee,
            'billing_day' => $billingDay,
        ]);
    }

    public function wallet(): static
    {
        return $this->state(fn () => [
            'type' => PlanType::Wallet,
            'monthly_fee' => null,
            'billing_day' => null,
        ]);
    }
}
