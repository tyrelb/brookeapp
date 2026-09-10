<?php

namespace App\Actions;

use App\Enums\SessionStatus;
use App\Models\TrainingSession;
use Illuminate\Support\Facades\DB;

/**
 * Voids the ledger charges of a completed session and returns it to "scheduled"
 * so attendance can be corrected and the session completed again.
 */
class ReopenTrainingSession
{
    public function handle(TrainingSession $session): TrainingSession
    {
        return DB::transaction(function () use ($session) {
            $session->loadMissing('attendees.walletTransaction', 'attendees.members');

            foreach ($session->attendees as $attendee) {
                $attendee->walletTransaction?->update(['voided_at' => now()]);

                $attendee->update([
                    'subtotal' => 0,
                    'gst_amount' => 0,
                    'total' => 0,
                    'wallet_transaction_id' => null,
                ]);

                // Who attended and any per-member prices survive: correcting attendance
                // is the whole point of reopening. Only the money is cleared.
                $attendee->members()->update(['subtotal' => 0]);
            }

            $session->update([
                'status' => SessionStatus::Scheduled,
                'completed_at' => null,
                // A reopened cover session must stop earning until it is completed again,
                // the same way a client's charge is voided above. The names stay put.
                ...$session->isCover()
                    ? ['cover_subtotal' => null, 'cover_gst_amount' => null, 'cover_gst_rate' => null]
                    : [],
            ]);

            return $session->refresh();
        });
    }
}
