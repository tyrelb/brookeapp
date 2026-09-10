<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Models\Gym;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;

/**
 * A month's statement between the trainer and one gym, in both directions.
 *
 * Usage: what she owes the gym — one row per completed session, priced from the gym's
 * rate card for that group size, plus the monthly rate, with the GYM's GST on top
 * (which she reclaims as an input tax credit).
 *
 * Cover: what the gym owes her for training its own clients while the owner was away,
 * priced when the session was completed and carrying HER GST (which she remits).
 *
 * The two sides carry different taxes, so they are computed separately and only the
 * GST-inclusive totals are netted. Netting the pre-tax amounts under one rate would
 * quietly produce a number that is wrong for both parties.
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
            ->where('gym_cover', false)
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

        $cover = $this->coverRows($gym, $from, $to);

        $monthlyFee = $gym->chargesMonthly() ? round((float) $gym->monthly_fee, 2) : null;
        $subtotal = round($usage + ($monthlyFee ?? 0), 2);
        $gst = GstCalculator::onExclusive($subtotal, $gym->effectiveGstRate());
        $total = round($subtotal + $gst, 2);

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
                'covers_sessions' => $gym->coversSessions(),
            ],
            'period' => $from->format('Y-m'),
            'label' => $from->format('F Y'),
            'rows' => $rows,
            'cover_rows' => $cover['rows'],
            'summary' => [
                'sessions' => count($rows) - $excluded,
                'sessions_excluded' => $excluded,
                'people' => $people,
                'by_people' => $byPeople,
                'usage_subtotal' => $usage,
                'monthly_fee' => $monthlyFee,
                'subtotal' => $subtotal,
                'gst' => $gst,
                // Owed to the gym, before the cover credit. Kept as-is because finalized
                // snapshots already hold this key; net_total is what the screen shows.
                'total' => $total,
                'cover_sessions' => $cover['sessions'],
                'cover_sessions_excluded' => $cover['excluded'],
                'cover_people' => $cover['people'],
                'cover_by_people' => $cover['by_people'],
                'cover_subtotal' => $cover['subtotal'],
                'cover_gst' => $cover['gst'],
                'cover_gst_rate' => $cover['gst_rate'],
                'cover_total' => $cover['total'],
                'net_total' => round($total - $cover['total'], 2),
            ],
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Sessions where the trainer covered this gym's own clients, priced when they were
     * completed. The stored per-session GST is summed rather than recomputed on the
     * subtotal: sessions can straddle a rate change or a GST registration date, and
     * rounding each row is not the same as rounding their sum.
     *
     * @return array{rows: list<array<string, mixed>>, sessions: int, excluded: int, people: int, by_people: array<int, array<string, mixed>>, subtotal: float, gst: float, total: float, gst_rate: ?float}
     */
    private function coverRows(Gym $gym, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sessions = TrainingSession::query()
            ->forTrainer($gym->user_id)
            ->where('gym_id', $gym->id)
            ->where('status', SessionStatus::Completed->value)
            ->cover()
            ->whereBetween('starts_at', [$from, $to])
            ->with('service')
            ->orderBy('starts_at')
            ->get();

        $rows = [];
        $byPeople = [];
        $subtotal = 0.0;
        $gst = 0.0;
        $people = 0;
        $excluded = 0;
        $rates = [];

        foreach ($sessions as $session) {
            $count = $session->coverPeople();
            $rowSubtotal = round((float) $session->cover_subtotal, 2);
            $rowGst = round((float) $session->cover_gst_amount, 2);
            $billable = (bool) $session->gym_billable;

            $rows[] = [
                'session_id' => $session->id,
                'date' => $session->starts_at->toDateString(),
                'time' => $session->starts_at->format('H:i'),
                'service' => $session->service->name,
                'names' => $session->coverNames(),
                'people' => $count,
                'subtotal' => $rowSubtotal,
                'gst' => $rowGst,
                'total' => round($rowSubtotal + $rowGst, 2),
                'billable' => $billable,
            ];

            if (! $billable) {
                $excluded++;

                continue;
            }

            $people += $count;
            $subtotal = round($subtotal + $rowSubtotal, 2);
            $gst = round($gst + $rowGst, 2);
            $rates[] = $session->cover_gst_rate === null ? null : (float) $session->cover_gst_rate;
            $byPeople[$count] ??= ['sessions' => 0, 'rate' => $rowSubtotal, 'amount' => 0.0];
            $byPeople[$count]['sessions']++;
            $byPeople[$count]['amount'] = round($byPeople[$count]['amount'] + $rowSubtotal, 2);
        }

        ksort($byPeople);

        $distinct = array_values(array_unique($rates, SORT_REGULAR));

        return [
            'rows' => $rows,
            'sessions' => count($rows) - $excluded,
            'excluded' => $excluded,
            'people' => $people,
            'by_people' => $byPeople,
            'subtotal' => $subtotal,
            'gst' => $gst,
            'total' => round($subtotal + $gst, 2),
            // Null when the month mixes rates, so the view can say so rather than pick one.
            'gst_rate' => count($distinct) === 1 ? $distinct[0] : null,
        ];
    }
}
