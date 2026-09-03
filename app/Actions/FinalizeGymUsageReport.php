<?php

namespace App\Actions;

use App\Models\Gym;
use App\Models\GymUsageReport;
use App\Services\GymUsageReportBuilder;

/**
 * Locks a gym's month: stores the report as it stands so later session edits don't change it.
 */
class FinalizeGymUsageReport
{
    public function __construct(private GymUsageReportBuilder $builder) {}

    public function handle(Gym $gym, int $year, int $month): GymUsageReport
    {
        $report = $this->builder->build($gym, $year, $month);

        return GymUsageReport::query()->forTrainer($gym->user_id)->updateOrCreate(
            ['gym_id' => $gym->id, 'period' => $report['period']],
            ['user_id' => $gym->user_id, 'finalized_at' => now(), 'snapshot' => $report],
        );
    }
}
