<?php

namespace App\Livewire\Sessions;

use App\Actions\CompleteTrainingSession;
use App\Actions\ReopenTrainingSession;
use App\Actions\SendSessionCancellations;
use App\Actions\SendSessionInvites;
use App\Actions\SendSessionReceipts;
use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\TrainingSession;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    use PreviewsCharges;

    public TrainingSession $trainingSession;

    public string $notes = '';

    public string $clientSearch = '';

    /** @var array<int, array{attended: bool, override: string}> keyed by client id */
    public array $attendees = [];

    public bool $sendReceipts = false;

    // Reschedule modal
    public string $newServiceId = '';

    public string $newGymId = '';

    public string $newDate = '';

    public string $newTime = '';

    public string $newDuration = '';

    public function mount(TrainingSession $trainingSession): void
    {
        $this->authorize('view', $trainingSession);
        $this->trainingSession = $trainingSession;
        $this->notes = (string) $trainingSession->notes;
        $this->sendReceipts = (bool) auth()->user()->notify_on_completion;
        $this->syncAttendeesFromModel();
        $this->syncScheduleFromModel();
    }

    private function syncAttendeesFromModel(): void
    {
        $this->attendees = [];

        foreach ($this->trainingSession->attendees()->get() as $attendee) {
            $this->attendees[$attendee->client_id] = [
                'attended' => $attendee->attended,
                'override' => $attendee->price_override !== null ? number_format((float) $attendee->price_override, 2, '.', '') : '',
            ];
        }
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

        $client = Client::query()->find($clientId);

        if (! $client || isset($this->attendees[$clientId])) {
            return;
        }

        $this->trainingSession->attendees()->create(['client_id' => $clientId, 'attended' => true]);
        $this->attendees[$clientId] = ['attended' => true, 'override' => ''];
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

        $this->validate([
            'attendees.*.attended' => ['boolean'],
            'attendees.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($this->attendees as $clientId => $state) {
            $this->trainingSession->attendees()->where('client_id', $clientId)->update([
                'attended' => (bool) $state['attended'],
                'price_override' => ($state['override'] ?? '') !== '' ? round((float) $state['override'], 2) : null,
            ]);
        }

        $this->trainingSession->update(['notes' => $this->notes ?: null]);
    }

    public function complete(): void
    {
        $this->authorize('update', $this->trainingSession);
        $this->saveAttendance();

        if (empty($this->attendees)) {
            $this->addError('attendees', 'Add at least one client before completing.');

            return;
        }

        try {
            $this->trainingSession = app(CompleteTrainingSession::class)->handle($this->trainingSession->fresh());
        } catch (BillingException $e) {
            $this->addError('attendees', $e->getMessage());

            return;
        }

        $message = 'Session completed and attendees charged.';

        if ($this->sendReceipts) {
            $sent = app(SendSessionReceipts::class)->handle($this->trainingSession);
            $message .= $sent ? " Receipt emailed to {$sent} ".str('client')->plural($sent).'.' : ' No attendee has an email address, so no receipts were sent.';
        }

        Flux::toast($message, variant: 'success');
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

        $this->trainingSession->update([
            'service_id' => (int) $this->newServiceId,
            'gym_id' => $this->newGymId !== '' ? (int) $this->newGymId : null,
            'starts_at' => "{$this->newDate} {$this->newTime}:00",
            'duration_minutes' => (int) $this->newDuration,
        ]);

        $message = 'Session rescheduled.';

        if ($this->trainingSession->invitesWereSent()) {
            $sent = app(SendSessionInvites::class)->handle($this->trainingSession->fresh(), isUpdate: true);
            $message .= $sent ? " Updated invite emailed to {$sent} ".str('client')->plural($sent).'.' : '';
        }

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

        $this->trainingSession->update(['status' => SessionStatus::Cancelled]);
        $message = 'Session cancelled.';

        if ($this->trainingSession->invitesWereSent()) {
            $sent = app(SendSessionCancellations::class)->handle($this->trainingSession->fresh());
            $message .= $sent ? " Cancellation emailed to {$sent} ".str('client')->plural($sent).'.' : '';
        }

        Flux::toast($message, variant: 'success');
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
        $session = $this->trainingSession->fresh(['service', 'gym', 'attendees.client.plan.rates']);
        $this->trainingSession = $session;

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
        ])->title($session->service->name.' — '.$session->starts_at->format('M j, Y'));
    }
}
