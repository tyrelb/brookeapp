<?php

namespace App\Actions;

use App\Mail\SessionCancelledMail;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a cancellation (with a CANCEL .ics) to every attendee who was sent an invite.
 *
 * @return int number of cancellations queued
 */
class SendSessionCancellations
{
    public function handle(TrainingSession $session): int
    {
        $session->loadMissing(['service', 'trainer', 'attendees.client']);
        $session->increment('ics_sequence');

        $sent = 0;

        foreach ($session->attendees as $attendee) {
            $client = $attendee->client;

            if (! $client?->email || $attendee->invite_sent_at === null) {
                continue;
            }

            Mail::to($client->email, $client->full_name)->queue(new SessionCancelledMail($session, $client));
            $sent++;
        }

        return $sent;
    }
}
