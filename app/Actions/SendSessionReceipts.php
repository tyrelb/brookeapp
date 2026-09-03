<?php

namespace App\Actions;

use App\Exceptions\BillingException;
use App\Mail\SessionCompletedMail;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\Mail;

/**
 * Emails each attendee who trained a receipt with their charge and remaining balance.
 *
 * @return int number of receipts queued
 */
class SendSessionReceipts
{
    public function handle(TrainingSession $session): int
    {
        if (! $session->isCompleted()) {
            throw new BillingException('Receipts can only be sent for completed sessions.');
        }

        $session->loadMissing(['service', 'trainer', 'attendees.client.plan']);
        $sent = 0;

        foreach ($session->attendees as $attendee) {
            $client = $attendee->client;

            if (! $attendee->attended || ! $client?->email) {
                continue;
            }

            Mail::to($client->email, $client->full_name)->queue(new SessionCompletedMail($attendee));
            $attendee->forceFill(['receipt_sent_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }
}
