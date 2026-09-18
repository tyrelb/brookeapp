<?php

namespace App\Actions;

use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\WalletTransaction;
use App\Services\CoverFeePricer;
use App\Services\SessionPricer;
use App\Support\AttendeeLine;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Marks a session complete and charges every attendee according to their plan.
 * Wallet (pay-as-you-go) clients get a debit on their Fitness Wallet ledger;
 * monthly clients are recorded at $0 (included in their fee).
 *
 * A late cancel is charged exactly as if they had come — same tier, same price — and
 * must say why, because the reason is what the client reads on their receipt.
 */
class CompleteTrainingSession
{
    public function __construct(private SessionPricer $pricer, private CoverFeePricer $coverPricer) {}

    public function handle(TrainingSession $session, ?CarbonInterface $completedAt = null): TrainingSession
    {
        return DB::transaction(function () use ($session, $completedAt) {
            $session->loadMissing(['attendees.client.plan.rates', 'attendees.members', 'service', 'trainer', 'gym']);

            if ($session->isCompleted()) {
                throw new BillingException('This session has already been completed. Reopen it to make changes.');
            }

            if ($session->isCover()) {
                return $this->completeCover($session, $completedAt);
            }

            $lines = [];
            foreach ($session->attendees as $attendee) {
                if ($attendee->attended && $attendee->client?->isOnFamilyPlan() && $attendee->members->where('attended', true)->isEmpty()) {
                    throw new BillingException("Tick which {$attendee->client->full_name} members attended before completing this session.");
                }

                // syncMembers() clears `attended` when nobody is ticked, which would quietly
                // turn a family's late cancel into a free no-show.
                if ($attendee->late_cancelled && $attendee->client?->isOnFamilyPlan() && $attendee->members->where('attended', true)->isEmpty()) {
                    throw new BillingException("Tick which {$attendee->client->full_name} members were booked before charging the late cancel.");
                }

                if ($attendee->isLateCancel() && blank($attendee->client_note)) {
                    throw new BillingException("Add a reason for {$attendee->client?->full_name}'s late cancel. They'll see it on their receipt.");
                }

                $lines[$attendee->client_id] = AttendeeLine::fromAttendee($attendee);
            }

            $priced = $this->pricer->price($lines, $session->service, $session->trainer->effectiveGstRate());

            foreach ($session->attendees as $attendee) {
                $row = $priced['rows'][$attendee->client_id];

                if (! $row['attended']) {
                    $attendee->update(['subtotal' => 0, 'gst_amount' => 0, 'total' => 0, 'wallet_transaction_id' => null]);
                    $attendee->members()->update(['subtotal' => 0]);

                    continue;
                }

                if ($row['error'] !== null) {
                    throw new BillingException($row['error']);
                }

                $transaction = $row['total'] > 0
                    ? $this->charge($session, $attendee, $row['subtotal'], $row['gst'], $row['total'], $priced['tier'])
                    : null;

                $attendee->update([
                    'subtotal' => $row['subtotal'],
                    'gst_amount' => $row['gst'],
                    'total' => $row['total'],
                    'wallet_transaction_id' => $transaction?->id,
                ]);

                foreach ($row['members'] as $member) {
                    $attendee->members()->where('family_member_id', $member['id'])->update(['subtotal' => $member['subtotal']]);
                }
            }

            $session->update([
                'status' => SessionStatus::Completed,
                'completed_at' => $completedAt ?? now(),
            ]);

            return $session->refresh();
        });
    }

    /**
     * A cover session bills the gym, not a client: the fee is fixed by how many of the
     * gym's people were in the room, and it is frozen onto the session here so that
     * later edits to the rate card or to GST registration cannot restate a month that
     * has already been reported on.
     */
    private function completeCover(TrainingSession $session, ?CarbonInterface $completedAt): TrainingSession
    {
        if ($session->gym === null) {
            throw new BillingException('Pick the gym you covered for before completing this session.');
        }

        if ($session->attendees->isNotEmpty()) {
            throw new BillingException("A cover session can't also have your own clients on it. Log them as a separate session.");
        }

        $priced = $this->coverPricer->price(
            $session->gym,
            $session->coverNames(),
            $session->trainer->effectiveGstRate(),
        );

        $session->update([
            'cover_subtotal' => $priced['subtotal'],
            'cover_gst_amount' => $priced['gst'],
            'cover_gst_rate' => $priced['gst_rate'],
            'status' => SessionStatus::Completed,
            'completed_at' => $completedAt ?? now(),
        ]);

        return $session->refresh();
    }

    /**
     * "Personal Training (Triple)" for one person; a family's charge names who it covers,
     * because it is one ledger line standing in for several people. The trainer's reason
     * goes on the end, since this line is what the client reads on their wallet page.
     */
    private function describe(TrainingSession $session, SessionAttendee $attendee, string $tier): string
    {
        $line = "{$session->service->name} ({$tier})";

        if ($attendee->client?->isOnFamilyPlan()) {
            $line .= ' — '.implode(', ', $attendee->peopleNames());
        }

        $note = trim((string) $attendee->client_note);

        if ($attendee->isLateCancel()) {
            $line .= $note === '' ? ' — late cancel' : " — late cancel: {$note}";
        } elseif ($note !== '') {
            $line .= " — {$note}";
        }

        return Str::limit($line, 250);
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
            'description' => $this->describe($session, $attendee, $tier),
        ]);
    }
}
