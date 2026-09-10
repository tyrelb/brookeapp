<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\Gym;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;

/**
 * What the trainer owes a gym for a month: one row per completed session
 * (people who attended, and the gym's per-session rate for that group size),
 * plus the monthly rate and GST where the gym charges them.
 */
class GymUsageReportBuilder
{
    /**
     * @return array{gym: array<string, mixed>, period: string, label: string, rows: list<array<string, mixed>>, summary: array<string, mixed>, unassigned: int}
     */
    public function build(Gym $gym, int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $to = $from->endOfMonth();

        $sessions = TrainingSession::query()
            ->forTrainer($gym->user_id)
            ->where('gym_id', $gym->id)
            ->where('status', SessionStatus::Completed->value)
            ->whereBetween('starts_at', [$from, $to])
            ->with(['service', 'attendees.client.plan', 'attendees.members'])
            ->orderBy('starts_at')
            ->get();

        $rows = [];
        $byPeople = [];
        $usage = 0.0;
        $people = 0;
        $excluded = 0;

        foreach ($sessions as $session) {
            $count = $session->headcount();
            $rate = $gym->rateFor($count);
            $billable = (bool) $session->gym_billable;

            $rows[] = [
                'session_id' => $session->id,
                'date' => $session->starts_at->toDateString(),
                'time' => $session->starts_at->format('H:i'),
                'service' => $session->service->name,
                'attendees' => $session->peopleNames(),
                'people' => $count,
                'rate' => $rate,
                'billable' => $billable,
            ];

            if (! $billable) {
                $excluded++;

                continue;
            }

            $people += $count;
            $usage = round($usage + ($rate ?? 0), 2);
            $byPeople[$count] ??= ['sessions' => 0, 'rate' => $rate, 'amount' => 0.0];
            $byPeople[$count]['sessions']++;
            $byPeople[$count]['amount'] = round($byPeople[$count]['amount'] + ($rate ?? 0), 2);
        }

        ksort($byPeople);

        $monthlyFee = $gym->chargesMonthly() ? round((float) $gym->monthly_fee, 2) : null;
        $subtotal = round($usage + ($monthlyFee ?? 0), 2);
        $gst = GstCalculator::onExclusive($subtotal, $gym->effectiveGstRate());

        $unassigned = TrainingSession::query()
            ->forTrainer($gym->user_id)
            ->whereNull('gym_id')
            ->where('status', SessionStatus::Completed->value)
            ->whereBetween('starts_at', [$from, $to])
            ->count();

        return [
            'gym' => [
                'id' => $gym->id,
                'name' => $gym->name,
                'billing_model' => $gym->billing_model->value,
                'charges_gst' => $gym->charges_gst,
                'gst_rate' => (float) $gym->gst_rate,
            ],
            'period' => $from->format('Y-m'),
            'label' => $from->format('F Y'),
            'rows' => $rows,
            'summary' => [
                'sessions' => count($rows) - $excluded,
                'sessions_excluded' => $excluded,
                'people' => $people,
                'by_people' => $byPeople,
                'usage_subtotal' => $usage,
                'monthly_fee' => $monthlyFee,
                'subtotal' => $subtotal,
                'gst' => $gst,
                'total' => round($subtotal + $gst, 2),
            ],
            'unassigned' => $unassigned,
        ];
    }
}
