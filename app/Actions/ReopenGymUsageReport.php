<?php

namespace App\Actions;

use App\Models\Gym;
use App\Models\GymUsageReport;

/**
 * Unlocks a finalized month so rows can be included or excluded again.
 */
class ReopenGymUsageReport
{
    public function handle(Gym $gym, string $period): bool
    {
        return (bool) GymUsageReport::query()
            ->forTrainer($gym->user_id)
            ->where('gym_id', $gym->id)
            ->where('period', $period)
            ->delete();
    }
}
