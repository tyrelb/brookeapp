<?php

namespace App\Livewire\Sessions\Concerns;

use App\Actions\CompleteTrainingSession;
use App\Actions\SendSessionReceipts;
use App\Enums\Attendance;
use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use Flux\Flux;
use Illuminate\Validation\Rule;

/**
 * Who came to a booked session, and completing it: shared by the session page and the
 * calendar's session sheet so the two cannot mark or charge a session differently.
 * Uses SelectsMembers for the family ticks.
 *
 * @property TrainingSession $trainingSession
 * @property array<int, array{attendance: string, override: string, client_note: string, members: array<int, array{attended: bool, override: string}>}> $attendees keyed by client id
 * @property bool $sendReceipts
 */
trait RecordsAttendance
{
    /** Validates and saves the attendance form (scheduled sessions only). */
    abstract public function saveAttendance(): void;

    protected function syncAttendeesFromModel(): void
    {
        $this->attendees = [];

        foreach ($this->trainingSession->attendees()->with('client.activeMembers', 'members')->get() as $attendee) {
            $this->attendees[$attendee->client_id] = [
                'attendance' => $this->attendanceStateFor($attendee),
                'override' => $attendee->price_override !== null ? number_format((float) $attendee->price_override, 2, '.', '') : '',
                'client_note' => (string) $attendee->client_note,
                'members' => $this->memberStateFor($attendee),
            ];
        }
    }

    /**
     * A family's row is only "not attended" because nobody is ticked yet, which says nothing
     * about whether they came; reading it through the attendance enum would turn a saved
     * late cancel into a no-show the moment the page reloads.
     */
    private function attendanceStateFor(SessionAttendee $attendee): string
    {
        if ($attendee->client?->isOnFamilyPlan()) {
            return $attendee->late_cancelled ? Attendance::LateCancel->value : Attendance::Attended->value;
        }

        return $attendee->attendance()->value;
    }

    /**
     * Every member currently on the family, plus anyone already on this session — so a
     * member added last week shows up on a session booked last month.
     *
     * @return array<int, array{attended: bool, override: string}>
     */
    private function memberStateFor(SessionAttendee $attendee): array
    {
        if (! $attendee->client?->isOnFamilyPlan()) {
            return [];
        }

        $saved = $attendee->members->keyBy('family_member_id');
        $state = [];

        foreach ($attendee->client->activeMembers as $member) {
            $state[$member->id] = [
                'attended' => (bool) ($saved[$member->id]->attended ?? false),
                'override' => isset($saved[$member->id]) && $saved[$member->id]->price_override !== null
                    ? number_format((float) $saved[$member->id]->price_override, 2, '.', '')
                    : '',
            ];
        }

        // Members deactivated since the session was booked stay put if they are on it.
        foreach ($saved as $memberId => $row) {
            $state[$memberId] ??= [
                'attended' => (bool) $row->attended,
                'override' => $row->price_override !== null ? number_format((float) $row->price_override, 2, '.', '') : '',
            ];
        }

        return $state;
    }

    /** @return array<string, list<mixed>> */
    protected function attendanceRules(): array
    {
        return [
            'attendees.*.attendance' => [Rule::in(['attended', 'late_cancel', 'no_show'])],
            'attendees.*.client_note' => ['nullable', 'string', 'max:150'],
            'attendees.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'attendees.*.members' => ['array', 'max:'.Client::MAX_MEMBERS],
            'attendees.*.members.*.attended' => ['boolean'],
            'attendees.*.members.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    protected function attendanceMessages(): array
    {
        return ['attendees.*.client_note.max' => 'Keep the reason under 150 characters.'];
    }

    /** Writes the validated form onto the attendee rows. Call inside a transaction. */
    protected function writeAttendance(): void
    {
        $rows = $this->trainingSession->attendees()->with('client.members')->get()->keyBy('client_id');

        foreach ($this->attendees as $clientId => $state) {
            $attendee = $rows->get((int) $clientId);

            if (! $attendee) {
                continue;
            }

            $isFamily = $attendee->client?->isOnFamilyPlan() ?? false;

            $attendee->update([
                'attended' => $isFamily ? $attendee->attended : $this->stateAttends($attendee->client, $state),
                // Kept for a family even before anyone is ticked, so the choice survives
                // a save; completing refuses a late cancel with nobody booked.
                'late_cancelled' => $this->stateLateCancelled($state),
                'client_note' => $this->stateClientNote($state),
                'price_override' => ! $isFamily && ($state['override'] ?? '') !== ''
                    ? round((float) $state['override'], 2)
                    : null,
            ]);

            if ($isFamily) {
                // syncMembers settles `attended` from the ticks themselves.
                $attendee->syncMembers($this->memberSelection($state));
            }
        }
    }

    /**
     * Saves attendance, then completes the session and charges everyone on the bill.
     * Problems land on the `attendees` error bag rather than throwing.
     */
    protected function completeSession(): bool
    {
        $this->authorize('update', $this->trainingSession);
        $this->saveAttendance();

        if (empty($this->attendees) && ! $this->trainingSession->isCover()) {
            $this->addError('attendees', 'Add at least one client before completing.');

            return false;
        }

        try {
            $this->trainingSession = app(CompleteTrainingSession::class)->handle($this->trainingSession->fresh());
        } catch (BillingException $e) {
            $this->addError('attendees', $e->getMessage());

            return false;
        }

        if ($this->trainingSession->isCover()) {
            Flux::toast(
                'Cover session completed. '.money($this->trainingSession->coverTotal())
                    ." credited to you on {$this->trainingSession->gym->name}'s statement.",
                variant: 'success',
            );

            return true;
        }

        $message = 'Session completed and attendees charged.';

        if ($this->sendReceipts) {
            $sent = app(SendSessionReceipts::class)->handle($this->trainingSession);
            $message .= $sent ? " Receipt emailed to {$sent} ".str('client')->plural($sent).'.' : ' No attendee has an email address, so no receipts were sent.';
        }

        Flux::toast($message, variant: 'success');

        return true;
    }
}
