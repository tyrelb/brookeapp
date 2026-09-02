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
            $session->loadMissing('attendees.walletTransaction');

            foreach ($session->attendees as $attendee) {
                $attendee->walletTransaction?->update(['voided_at' => now()]);

                $attendee->update([
                    'subtotal' => 0,
                    'gst_amount' => 0,
                    'total' => 0,
                    'wallet_transaction_id' => null,
                ]);
            }

            $session->update([
                'status' => SessionStatus::Scheduled,
                'completed_at' => null,
            ]);

            return $session->refresh();
        });
    }
}
