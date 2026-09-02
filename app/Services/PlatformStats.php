<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\Client;
use App\Models\Scopes\TrainerScope;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Platform-wide counts for the admin area. Counts only: never dollar amounts,
 * which stay private to each trainer.
 */
class PlatformStats
{
    /**
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $now = CarbonImmutable::now();
        $trainers = User::query()->where('is_admin', false);

        return [
            'trainers' => (clone $trainers)->count(),
            'new_this_month' => (clone $trainers)->where('created_at', '>=', $now->startOfMonth())->count(),
            'new_last_month' => (clone $trainers)->whereBetween('created_at', [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()])->count(),
            'verified' => (clone $trainers)->whereNotNull('email_verified_at')->count(),
            'unverified' => (clone $trainers)->whereNull('email_verified_at')->count(),
            'active_30d' => (clone $trainers)->where('last_login_at', '>=', $now->subDays(30))->count(),
            'suspended' => (clone $trainers)->whereNotNull('suspended_at')->count(),
            'clients' => Client::withoutGlobalScope(TrainerScope::class)->count(),
            'sessions_this_month' => TrainingSession::withoutGlobalScope(TrainerScope::class)
                ->where('status', SessionStatus::Completed->value)
                ->whereBetween('starts_at', [$now->startOfMonth(), $now->endOfMonth()])
                ->count(),
            'sessions_total' => TrainingSession::withoutGlobalScope(TrainerScope::class)
                ->where('status', SessionStatus::Completed->value)
                ->count(),
        ];
    }

    /**
     * Trainer sign-ups per month for the last N months, oldest first.
     *
     * @return list<array{label: string, month: string, count: int}>
     */
    public function signupsByMonth(int $months = 12): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        $counts = User::query()
            ->where('is_admin', false)
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->countBy(fn (User $u) => $u->created_at->format('Y-m'));

        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $rows[] = [
                'label' => $month->format('M Y'),
                'month' => $month->format('Y-m'),
                'count' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        }

        return $rows;
    }
}
