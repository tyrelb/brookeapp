<?php

namespace App\Actions;

use App\Mail\SeriesMail;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "This and following": moves the anchor and every later scheduled session of its series.
 * Dates shift by the same number of days the anchor moved; time, duration, service and gym
 * take the new values. Earlier, completed and cancelled sessions are never touched.
 */
class RescheduleFollowing
{
    public function __construct(private SendSeriesInvites $invites) {}

    /**
     * @param  array{date:string, time:string, duration_minutes:int, service_id:int, gym_id:?int}  $changes
     * @return int number of sessions changed
     */
    public function handle(TrainingSession $anchor, array $changes): int
    {
        $series = $anchor->series;

        if (! $series) {
            return 0;
        }

        $following = $series->scheduledFrom($anchor);
        $deltaDays = (int) $anchor->starts_at->copy()->startOfDay()->diffInDays(CarbonImmutable::parse($changes['date'])->startOfDay(), false);

        DB::transaction(function () use ($following, $changes, $deltaDays, $series) {
            foreach ($following as $session) {
                $session->update([
                    'starts_at' => $session->starts_at->copy()->addDays($deltaDays)->format('Y-m-d').' '.$changes['time'].':00',
                    'duration_minutes' => $changes['duration_minutes'],
                    'service_id' => $changes['service_id'],
                    'gym_id' => $changes['gym_id'],
                    'ics_sequence' => $session->ics_sequence + 1,
                ]);
            }

            $series->update([
                'time' => $changes['time'],
                'duration_minutes' => $changes['duration_minutes'],
                'service_id' => $changes['service_id'],
                'gym_id' => $changes['gym_id'],
                'ends_on' => $series->ends_on->copy()->addDays($deltaDays),
            ]);
        });

        $invited = $following->filter(fn (TrainingSession $s) => $s->invitesWereSent());

        if ($invited->isNotEmpty()) {
            $this->invites->handle($invited->map->fresh(['service', 'gym', 'trainer', 'series', 'attendees.client']), SeriesMail::UPDATED);
        }

        return $following->count();
    }
}
