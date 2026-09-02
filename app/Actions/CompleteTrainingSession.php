<?php

namespace App\Actions;

use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\WalletTransaction;
use App\Services\GstCalculator;
use App\Services\PriceResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Marks a session complete and charges every attendee according to their plan.
 * Wallet (pay-as-you-go) clients get a debit on their Fitness Wallet ledger;
 * monthly clients are recorded at $0 (included in their fee).
 */
class CompleteTrainingSession
{
    public function __construct(private PriceResolver $prices) {}

    public function handle(TrainingSession $session, ?CarbonInterface $completedAt = null): TrainingSession
    {
        return DB::transaction(function () use ($session, $completedAt) {
            $session->loadMissing(['attendees.client.plan.rates', 'service', 'trainer']);

            if ($session->isCompleted()) {
                throw new BillingException('This session has already been completed. Reopen it to make changes.');
            }

            $gstRate = $session->trainer->effectiveGstRate();
            $headcount = max(1, $session->headcount());
            $tier = Plan::headcountLabel($headcount);

            foreach ($session->attendees as $attendee) {
                if (! $attendee->attended) {
                    $attendee->update(['subtotal' => 0, 'gst_amount' => 0, 'total' => 0, 'wallet_transaction_id' => null]);

                    continue;
                }

                $subtotal = $this->prices->forAttendee(
                    $attendee->client,
                    $session->service,
                    $headcount,
                    $attendee->price_override !== null ? (float) $attendee->price_override : null,
                );
                $gst = GstCalculator::onExclusive($subtotal, $gstRate);
                $total = round($subtotal + $gst, 2);

                $transaction = $total > 0 ? $this->charge($session, $attendee, $subtotal, $gst, $total, $tier) : null;

                $attendee->update([
                    'subtotal' => $subtotal,
                    'gst_amount' => $gst,
                    'total' => $total,
                    'wallet_transaction_id' => $transaction?->id,
                ]);
            }

            $session->update([
                'status' => SessionStatus::Completed,
                'completed_at' => $completedAt ?? now(),
            ]);

            return $session->refresh();
        });
    }

    private function charge(TrainingSession $session, SessionAttendee $attendee, float $subtotal, float $gst, float $total, string $tier): WalletTransaction
    {
        return WalletTransaction::create([
            'user_id' => $session->user_id,
            'client_id' => $attendee->client_id,
            'type' => TransactionType::SessionCharge,
            'amount' => -$total,
            'subtotal' => $subtotal,
            'gst_amount' => $gst,
            'transacted_on' => $session->starts_at->toDateString(),
            'training_session_id' => $session->id,
            'description' => "{$session->service->name} ({$tier})",
        ]);
    }
}
