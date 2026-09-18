<?php

namespace App\Actions;

use App\Enums\SessionStatus;
use App\Models\TrainingSession;

/**
 * Cancels one scheduled session. Nobody is charged, and anyone who was sent a calendar
 * invite gets a cancellation so it drops off their calendar. "This and following" is
 * CancelFollowing.
 */
class CancelTrainingSession
{
    public function __construct(private SendSessionCancellations $cancellations) {}

    /** @return int number of cancellation emails queued */
    public function handle(TrainingSession $session): int
    {
        $session->update(['status' => SessionStatus::Cancelled]);

        return $session->invitesWereSent() ? $this->cancellations->handle($session->fresh()) : 0;
    }
}
