<?php

namespace App\Actions;

use App\Mail\SessionBookedMail;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\Mail;

/**
 * Emails every attendee with an address a calendar invite for a scheduled session.
 * Pass $isUpdate after a reschedule so the invite replaces the earlier one.
 *
 * @return int number of invites queued
 */
class SendSessionInvites
{
    public function handle(TrainingSession $session, bool $isUpdate = false): int
    {
        $session->loadMissing(['service', 'trainer', 'attendees.client']);
        $session->ensureIcsUid();

        if ($isUpdate) {
            $session->increment('ics_sequence');
        }

        $sent = 0;

        foreach ($session->attendees as $attendee) {
            $client = $attendee->client;

            if (! $client?->email) {
                continue;
            }

            Mail::to($client->email, $client->full_name)->queue(new SessionBookedMail($session, $client, $isUpdate));
            $attendee->forceFill(['invite_sent_at' => now()])->save();
            $sent++;
        }

        if ($sent > 0) {
            $session->forceFill(['invites_sent_at' => now()])->save();
        }

        return $sent;
    }
}
