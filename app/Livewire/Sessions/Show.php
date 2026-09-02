<?php

namespace App\Livewire\Sessions;

use App\Actions\CompleteTrainingSession;
use App\Actions\ReopenTrainingSession;
use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Models\Client;
use App\Models\TrainingSession;
use Flux\Flux;
use Livewire\Component;

class Show extends Component
{
    use PreviewsCharges;

    public TrainingSession $trainingSession;

    public string $notes = '';

    public string $clientSearch = '';

    /** @var array<int, array{attended: bool, override: string}> keyed by client id */
    public array $attendees = [];

    public function mount(TrainingSession $trainingSession): void
    {
        $this->authorize('view', $trainingSession);
        $this->trainingSession = $trainingSession;
        $this->notes = (string) $trainingSession->notes;
        $this->syncAttendeesFromModel();
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

        Flux::toast('Session completed and attendees charged.', variant: 'success');
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
        Flux::toast('Session cancelled.', variant: 'success');
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

        $this->trainingSession->delete();
        Flux::toast('Session deleted.', variant: 'success');
        $this->redirectRoute('sessions.index', navigate: true);
    }

    public function render()
    {
        $session = $this->trainingSession->fresh(['service', 'attendees.client.plan.rates']);
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
            'preview' => $session->isScheduled() ? $this->previewCharges($session->service, $selected) : null,
        ])->title($session->service->name.' — '.$session->starts_at->format('M j, Y'));
    }
}
