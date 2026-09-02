<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanRate>
 */
class PlanRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'service_id' => Service::factory(),
            'headcount' => 1,
            'unit_price' => 60.00,
        ];
    }
}
