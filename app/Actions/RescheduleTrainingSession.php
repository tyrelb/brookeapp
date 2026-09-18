<?php

namespace App\Actions;

use App\Models\TrainingSession;

/**
 * Moves one scheduled session, and emails an updated invite to anyone who was already
 * sent one so their calendar follows. "This and following" is RescheduleFollowing.
 */
class RescheduleTrainingSession
{
    public function __construct(private SendSessionInvites $invites) {}

    /**
     * @param  array{date:string, time:string, duration_minutes:int, service_id:int, gym_id:?int}  $changes
     * @return int number of updated invites queued
     */
    public function handle(TrainingSession $session, array $changes): int
    {
        $session->update([
            'service_id' => $changes['service_id'],
            'gym_id' => $changes['gym_id'],
            'starts_at' => "{$changes['date']} {$changes['time']}:00",
            'duration_minutes' => $changes['duration_minutes'],
        ]);

        return $session->invitesWereSent() ? $this->invites->handle($session->fresh(), isUpdate: true) : 0;
    }
}
