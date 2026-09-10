<?php

namespace App\Actions;

use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates one session per date from a single set-up — same service, gym, duration
 * and attendees — so a backlog of sessions can be entered (or imported) in one pass.
 * Everything succeeds or nothing does.
 */
class LogSessionsInBulk
{
    /** Enough for a season of history without turning one click into an unreviewable batch. */
    public const MAX_SESSIONS = 50;

    /** Sessions are logged at this time when the trainer does not care about the clock. */
    public const DEFAULT_TIME = '09:00';

    public function __construct(private CompleteTrainingSession $complete) {}

    /**
     * @param  array{user_id?:int, service_id:int, gym_id:?int, time:?string, duration_minutes:int, notes:?string}  $attributes
     * @param  list<string>  $dates
     * @param  array<int, array{attended?: bool, price_override?: ?float, members?: array<int, array{attended: bool, price_override: ?float}>}>  $attendees  keyed by client id
     * @return list<TrainingSession>
     */
    public function handle(array $attributes, array $dates, array $attendees, bool $complete = true): array
    {
        $dates = $this->normalizeDates($dates);

        if ($attendees === []) {
            throw new BillingException('Add at least one client.');
        }

        $time = $this->normalizeTime($attributes['time'] ?? null);

        return DB::transaction(function () use ($attributes, $dates, $attendees, $complete, $time) {
            $sessions = [];

            foreach ($dates as $date) {
                $session = TrainingSession::create([
                    'user_id' => $attributes['user_id'] ?? auth()->id(),
                    'service_id' => $attributes['service_id'],
                    'gym_id' => $attributes['gym_id'] ?? null,
                    'starts_at' => "{$date} {$time}:00",
                    'duration_minutes' => $attributes['duration_minutes'],
                    'status' => SessionStatus::Scheduled,
                    'notes' => $attributes['notes'] ?? null,
                ]);

                foreach ($attendees as $clientId => $state) {
                    $attendee = $session->attendees()->create([
                        'client_id' => (int) $clientId,
                        'attended' => (bool) ($state['attended'] ?? true),
                        'price_override' => $state['price_override'] ?? null,
                    ]);

                    if (($state['members'] ?? []) !== []) {
                        $attendee->syncMembers($state['members']);
                    }
                }

                $sessions[] = $complete ? $this->complete->handle($session) : $session;
            }

            return $sessions;
        });
    }

    /**
     * @param  list<string>  $dates
     * @return list<string> unique, valid, oldest first
     */
    public function normalizeDates(array $dates): array
    {
        $valid = [];

        foreach ($dates as $date) {
            $date = trim((string) $date);

            if ($date === '' || ! strtotime($date)) {
                throw new BillingException("\"{$date}\" is not a date we can read. Use YYYY-MM-DD.");
            }

            $valid[] = CarbonImmutable::parse($date)->toDateString();
        }

        $valid = array_values(array_unique($valid));
        sort($valid);

        if ($valid === []) {
            throw new BillingException('Pick at least one date.');
        }

        if (count($valid) > self::MAX_SESSIONS) {
            throw new BillingException('That is '.count($valid).' dates. Log at most '.self::MAX_SESSIONS.' sessions at a time.');
        }

        return $valid;
    }

    private function normalizeTime(?string $time): string
    {
        $time = trim((string) $time);

        if ($time === '' || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return self::DEFAULT_TIME;
        }

        return $time;
    }
}
