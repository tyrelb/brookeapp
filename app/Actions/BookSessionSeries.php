<?php

namespace App\Actions;

use App\Enums\SessionStatus;
use App\Models\SessionSeries;
use App\Models\TrainingSession;
use App\Support\Recurrence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a repeating booking: the series row plus one scheduled session per occurrence,
 * each with the same attendees. Optionally emails every attendee one invite covering all dates.
 */
class BookSessionSeries
{
    public function __construct(private SendSeriesInvites $invites) {}

    /**
     * @param  array{user_id:int, service_id:int, gym_id:?int, starts_on:string, ends_on:string, time:string, duration_minutes:int, interval_weeks:int, weekdays:list<int>, notes:?string}  $pattern
     * @param  array<int, array{price_override?: ?float}>  $attendees  keyed by client id
     */
    public function handle(array $pattern, array $attendees, bool $sendInvites = false): SessionSeries
    {
        $dates = Recurrence::occurrences($pattern['starts_on'], $pattern['ends_on'], $pattern['weekdays'], $pattern['interval_weeks']);

        $series = DB::transaction(function () use ($pattern, $attendees, $dates) {
            $series = SessionSeries::create([
                'user_id' => $pattern['user_id'],
                'service_id' => $pattern['service_id'],
                'gym_id' => $pattern['gym_id'] ?? null,
                'starts_on' => $pattern['starts_on'],
                'ends_on' => $pattern['ends_on'],
                'time' => $pattern['time'],
                'duration_minutes' => $pattern['duration_minutes'],
                'interval_weeks' => $pattern['interval_weeks'],
                'weekdays' => array_values(array_map('intval', $pattern['weekdays'])),
                'notes' => $pattern['notes'] ?? null,
            ]);

            foreach ($dates as $date) {
                $session = TrainingSession::create([
                    'user_id' => $pattern['user_id'],
                    'service_id' => $pattern['service_id'],
                    'gym_id' => $pattern['gym_id'] ?? null,
                    'session_series_id' => $series->id,
                    'starts_at' => $date->format('Y-m-d').' '.$pattern['time'].':00',
                    'duration_minutes' => $pattern['duration_minutes'],
                    'status' => SessionStatus::Scheduled,
                    'notes' => $pattern['notes'] ?? null,
                    'ics_uid' => (string) Str::uuid().'@brookeapp',
                ]);

                foreach ($attendees as $clientId => $state) {
                    $attendee = $session->attendees()->create([
                        'client_id' => $clientId,
                        'attended' => true,
                        'price_override' => $state['price_override'] ?? null,
                    ]);

                    // A family repeats with the members the trainer picked, not the whole household.
                    if (($state['members'] ?? []) !== []) {
                        $attendee->syncMembers($state['members']);
                    }
                }
            }

            return $series;
        });

        if ($sendInvites) {
            $this->invites->handle($series->sessions()->get());
        }

        return $series;
    }
}
