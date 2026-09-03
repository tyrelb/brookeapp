<?php

namespace Database\Factories;

use App\Enums\GymBillingModel;
use App\Models\Gym;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gym>
 */
class GymFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->company().' Gym',
            'billing_model' => GymBillingModel::Usage,
            'monthly_fee' => null,
            'usage_rates' => Gym::DEFAULT_RATES,
            'charges_gst' => true,
            'gst_rate' => 5.00,
            'is_default' => false,
            'active' => true,
        ];
    }

    public function monthly(float $fee = 500): static
    {
        return $this->state(fn () => ['billing_model' => GymBillingModel::Monthly, 'monthly_fee' => $fee]);
    }

    public function monthlyPlusUsage(float $fee = 200): static
    {
        return $this->state(fn () => ['billing_model' => GymBillingModel::MonthlyPlusUsage, 'monthly_fee' => $fee]);
    }

    public function noGst(): static
    {
        return $this->state(fn () => ['charges_gst' => false]);
    }
}
