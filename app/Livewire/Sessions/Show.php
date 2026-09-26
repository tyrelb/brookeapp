<?php

namespace App\Livewire\Sessions;

use App\Actions\ApplyAttendeesToFollowing;
use App\Actions\CancelFollowing;
use App\Actions\CancelTrainingSession;
use App\Actions\ReopenTrainingSession;
use App\Actions\RescheduleFollowing;
use App\Actions\RescheduleTrainingSession;
use App\Actions\SendSessionCancellations;
use App\Actions\SendSessionInvites;
use App\Actions\SendSessionReceipts;
use App\Enums\Attendance;
use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Livewire\Sessions\Concerns\PreviewsGymCharge;
use App\Livewire\Sessions\Concerns\RecordsAttendance;
use App\Livewire\Sessions\Concerns\SelectsMembers;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\TrainingSession;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    use PreviewsCharges, PreviewsGymCharge, RecordsAttendance, SelectsMembers;

    public TrainingSession $trainingSession;

    public string $notes = '';

    public string $clientSearch = '';

    /** @var array<int, array{attendance: string, override: string, client_note: string, members: array<int, array{attended: bool, override: string}>}> keyed by client id */
    public array $attendees = [];

    public bool $sendReceipts = false;

    // Reschedule modal
    public string $newServiceId = '';

    public string $newGymId = '';

    public string $newDate = '';

    public string $newTime = '';

    public string $newDuration = '';

    public string $rescheduleScope = 'one'; // one | following

    public function mount(TrainingSession $trainingSession): void
    {
        $this->authorize('view', $trainingSession);
        $this->trainingSession = $trainingSession;
        $this->notes = (string) $trainingSession->notes;
        $this->sendReceipts = (bool) auth()->user()->notify_on_completion;
        $this->syncAttendeesFromModel();
        $this->syncScheduleFromModel();
    }

    private function syncScheduleFromModel(): void
    {
        $this->newServiceId = (string) $this->trainingSession->service_id;
        $this->newGymId = (string) ($this->trainingSession->gym_id ?? '');
        $this->newDate = $this->trainingSession->starts_at->toDateString();
        $this->newTime = $this->trainingSession->starts_at->format('H:i');
        $this->newDuration = (string) $this->trainingSession->duration_minutes;
    }

    public function addClient(int $clientId): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled()) {
            return;
        }

        $client = Client::query()->with('activeMembers')->find($clientId);

        if (! $client || isset($this->attendees[$clientId])) {
            return;
        }

        DB::transaction(function () use ($client, $clientId) {
            $attendee = $this->trainingSession->attendees()->create([
                'client_id' => $clientId,
                // A family only counts once the trainer says who is coming.
                'attended' => ! $client->isOnFamilyPlan(),
            ]);

            if ($client->isOnFamilyPlan()) {
                $attendee->setRelation('client', $client);
                $attendee->syncMembers($client->activeMembers->mapWithKeys(
                    fn ($member) => [$member->id => ['attended' => false, 'price_override' => null]]
                )->all());
            }
        });

        $this->attendees[$clientId] = [
            'attendance' => Attendance::Attended->value,
            'override' => '',
            'client_note' => '',
            'members' => $client->activeMembers->mapWithKeys(fn ($m) => [$m->id => ['attended' => false, 'override' => '']])->all(),
        ];
        $this->clientSearch = '';
    }

    public function removeClient(int $clientId): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled()) {
            return;
        }

        $this->trainingSession->attendees()->where('client_id', $clientId)->delete();
        unset($this->attendees[$clientId]);
    }

    /**
     * Persist attendance / overrides (scheduled sessions only).
     */
    public function saveAttendance(): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled()) {
            return;
        }

        $this->validate(
            [...$this->attendanceRules(), 'notes' => ['nullable', 'string', 'max:2000']],
            $this->attendanceMessages(),
        );

        DB::transaction(function () {
            $this->writeAttendance();
            $this->trainingSession->update(['notes' => $this->notes ?: null]);
        });
    }

    public function complete(): void
    {
        $this->completeSession();
    }

    public function sendReceiptsNow(): void
    {
        $this->authorize('update', $this->trainingSession);

        try {
            $sent = app(SendSessionReceipts::class)->handle($this->trainingSession);
        } catch (BillingException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast($sent ? "Receipt emailed to {$sent} ".str('client')->plural($sent).'.' : 'No attendee has an email address.', variant: $sent ? 'success' : 'warning');
    }

    public function sendInvites(): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled()) {
            return;
        }

        $sent = app(SendSessionInvites::class)->handle($this->trainingSession, isUpdate: $this->trainingSession->invitesWereSent());

        Flux::toast($sent ? "Calendar invite emailed to {$sent} ".str('client')->plural($sent).'.' : 'No attendee has an email address.', variant: $sent ? 'success' : 'warning');
    }

    public function reschedule(): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled()) {
            return;
        }

        $this->validate([
            'newServiceId' => ['required', Rule::exists('services', 'id')->where('user_id', auth()->id())],
            'newGymId' => [Rule::requiredIf(Gym::query()->active()->exists()), 'nullable', Rule::exists('gyms', 'id')->where('user_id', auth()->id())],
            'newDate' => ['required', 'date'],
            'newTime' => ['required', 'date_format:H:i'],
            'newDuration' => ['required', 'integer', 'min:5', 'max:480'],
        ]);

        if ($this->rescheduleScope === 'following' && $this->trainingSession->isInSeries()) {
            $changed = app(RescheduleFollowing::class)->handle($this->trainingSession, [
                'date' => $this->newDate,
                'time' => $this->newTime,
                'duration_minutes' => (int) $this->newDuration,
                'service_id' => (int) $this->newServiceId,
                'gym_id' => $this->newGymId !== '' ? (int) $this->newGymId : null,
            ]);

            Flux::modal('reschedule')->close();
            Flux::toast("Rescheduled this and {$changed} following ".str('session')->plural($changed).'. Earlier sessions were left as they were; invited clients get one updated invite.', variant: 'success');

            return;
        }

        $sent = app(RescheduleTrainingSession::class)->handle($this->trainingSession, [
            'date' => $this->newDate,
            'time' => $this->newTime,
            'duration_minutes' => (int) $this->newDuration,
            'service_id' => (int) $this->newServiceId,
            'gym_id' => $this->newGymId !== '' ? (int) $this->newGymId : null,
        ]);

        $message = 'Session rescheduled.'.($sent ? " Updated invite emailed to {$sent} ".str('client')->plural($sent).'.' : '');

        Flux::modal('reschedule')->close();
        Flux::toast($message, variant: 'success');
    }

    /** Sets the gym for any session (including completed ones) and toggles whether it counts on the gym report. */
    public function setGym(string $gymId): void
    {
        $this->authorize('update', $this->trainingSession);
        if ($gymId === '' && Gym::query()->active()->exists()) {
            Flux::toast('Pick a gym so this session shows on the gym usage report.', variant: 'warning');
            $this->newGymId = (string) ($this->trainingSession->gym_id ?? '');

            return;
        }

        // Validate the value that is saved, not whatever the bound property happens to hold.
        $this->newGymId = $gymId;
        $this->validate(['newGymId' => ['nullable', Rule::exists('gyms', 'id')->where('user_id', auth()->id())]]);
        $this->trainingSession->update(['gym_id' => $gymId !== '' ? (int) $gymId : null]);
        Flux::toast('Gym updated.', variant: 'success');
    }

    public function toggleGymBillable(): void
    {
        $this->authorize('update', $this->trainingSession);
        $this->trainingSession->update(['gym_billable' => ! $this->trainingSession->gym_billable]);
    }

    public function reopen(): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isCompleted()) {
            return;
        }

        $this->trainingSession = app(ReopenTrainingSession::class)->handle($this->trainingSession);
        $this->syncAttendeesFromModel();
        Flux::toast('Session reopened. Charges were voided; fix attendance and complete it again.', variant: 'success');
    }

    public function cancel(): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled()) {
            return;
        }

        $sent = app(CancelTrainingSession::class)->handle($this->trainingSession);

        Flux::toast('Session cancelled.'.($sent ? " Cancellation emailed to {$sent} ".str('client')->plural($sent).'.' : ''), variant: 'success');
    }

    public function cancelFollowing(): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled() || ! $this->trainingSession->isInSeries()) {
            return;
        }

        $count = app(CancelFollowing::class)->handle($this->trainingSession);
        Flux::toast("Cancelled this and the following sessions ({$count} in total). Invited clients get one cancellation email.", variant: 'success');
    }

    public function applyAttendeesToFollowing(): void
    {
        $this->authorize('update', $this->trainingSession);

        if (! $this->trainingSession->isScheduled() || ! $this->trainingSession->isInSeries()) {
            return;
        }

        $this->saveAttendance();
        $count = app(ApplyAttendeesToFollowing::class)->handle($this->trainingSession->fresh());
        Flux::toast("Attendees copied to {$count} following ".str('session')->plural($count).'.', variant: 'success');
    }

    public function uncancel(): void
    {
        $this->authorize('update', $this->trainingSession);

        if ($this->trainingSession->status !== SessionStatus::Cancelled) {
            return;
        }

        $this->trainingSession->update(['status' => SessionStatus::Scheduled]);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->trainingSession);

        if ($this->trainingSession->isCompleted()) {
            Flux::toast('Reopen the session before deleting it so the charges are voided.', variant: 'warning');

            return;
        }

        if ($this->trainingSession->isScheduled() && $this->trainingSession->invitesWereSent()) {
            app(SendSessionCancellations::class)->handle($this->trainingSession);
        }

        $this->trainingSession->delete();
        Flux::toast('Session deleted.', variant: 'success');
        $this->redirectRoute('sessions.index', navigate: true);
    }

    public function render()
    {
        $session = $this->trainingSession->fresh(['service', 'gym', 'series', 'attendees.client.plan.rates']);
        $this->trainingSession = $session;
        $following = $session->isInSeries() && $session->isScheduled() ? $session->followingInSeries()->count() : 0;

        $selected = $session->attendees->pluck('client')->keyBy('id');

        $candidates = $session->isScheduled()
            ? Client::query()->active()->with('plan')
                ->whereNotIn('id', array_keys($this->attendees))
                ->search($this->clientSearch)
                ->orderBy('first_name')->orderBy('last_name')
                ->limit($this->clientSearch === '' ? 8 : 25)
                ->get()
            : collect();

        return view('livewire.sessions.show', [
            'session' => $session,
            'candidates' => $candidates,
            'services' => Service::query()->where('active', true)->orderBy('name')->get(),
            'gyms' => Gym::query()->orderByDesc('active')->orderBy('name')->get(),
            'preview' => $session->isScheduled() ? $this->previewCharges($session->service, $selected) : null,
            // Real persisted values, so this is the charge itself rather than a preview of one.
            'gymCharge' => $session->isCover() ? null : $this->gymChargeFor($session->gym, $session->roomHeadcount(), $session->duration_minutes),
            'following' => $following,
        ])->title($session->service->name.' — '.$session->starts_at->format('M j, Y'));
    }
}
