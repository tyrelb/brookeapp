<?php

namespace App\Livewire\Sessions;

use App\Actions\CancelFollowing;
use App\Actions\CancelTrainingSession;
use App\Actions\RescheduleFollowing;
use App\Actions\RescheduleTrainingSession;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Livewire\Sessions\Concerns\RecordsAttendance;
use App\Livewire\Sessions\Concerns\SelectsMembers;
use App\Models\TrainingSession;
use App\Support\SessionConflicts;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The sheet that slides up when a session is tapped in the calendar's list: log it, move
 * it or cancel it without leaving the day. Anything bigger (attendees, service, gym,
 * price overrides, reopening) stays on the full session page it links to.
 */
class SessionSheet extends Component
{
    use PreviewsCharges, RecordsAttendance, SelectsMembers;

    private const MODES = ['summary', 'log', 'edit', 'cancel'];

    public ?TrainingSession $trainingSession = null;

    public string $mode = 'summary';

    /** @var array<int, array{attendance: string, override: string, client_note: string, members: array<int, array{attended: bool, override: string}>}> keyed by client id */
    public array $attendees = [];

    public bool $sendReceipts = false;

    public string $newDate = '';

    public string $newTime = '';

    public string $newDuration = '';

    public string $rescheduleScope = 'one'; // one | following

    #[On('open-session')]
    public function open(int $id): void
    {
        $session = TrainingSession::query()->findOrFail($id);
        $this->authorize('view', $session);

        $this->trainingSession = $session;
        $this->mode = 'summary';
        $this->resetErrorBag();
        $this->sendReceipts = (bool) auth()->user()->notify_on_completion;
        $this->syncAttendeesFromModel();
        $this->newDate = $session->starts_at->toDateString();
        $this->newTime = $session->starts_at->format('H:i');
        $this->newDuration = (string) $session->duration_minutes;
        $this->rescheduleScope = 'one';
    }

    public function switchTo(string $mode): void
    {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : 'summary';
        $this->resetErrorBag();
    }

    public function saveAttendance(): void
    {
        if (! $this->trainingSession?->isScheduled()) {
            return;
        }

        $this->authorize('update', $this->trainingSession);

        $this->validate($this->attendanceRules(), $this->attendanceMessages());

        DB::transaction(fn () => $this->writeAttendance());
    }

    public function log(): void
    {
        if (! $this->trainingSession?->isScheduled()) {
            return;
        }

        if ($this->completeSession()) {
            $this->finish();
        }
    }

    public function reschedule(): void
    {
        if (! $this->trainingSession?->isScheduled()) {
            return;
        }

        $this->authorize('update', $this->trainingSession);

        $this->validate([
            'newDate' => ['required', 'date'],
            'newTime' => ['required', 'date_format:H:i'],
            'newDuration' => ['required', 'integer', 'min:5', 'max:'.SessionConflicts::MAX_DURATION_MINUTES],
        ]);

        // The sheet only moves the session; service and gym stay as booked.
        $changes = [
            'date' => $this->newDate,
            'time' => $this->newTime,
            'duration_minutes' => (int) $this->newDuration,
            'service_id' => $this->trainingSession->service_id,
            'gym_id' => $this->trainingSession->gym_id,
        ];

        if ($this->rescheduleScope === 'following' && $this->trainingSession->isInSeries()) {
            $changed = app(RescheduleFollowing::class)->handle($this->trainingSession, $changes);
            Flux::toast("Rescheduled this and {$changed} following ".str('session')->plural($changed).'. Earlier sessions were left as they were; invited clients get one updated invite.', variant: 'success');
        } else {
            $sent = app(RescheduleTrainingSession::class)->handle($this->trainingSession, $changes);
            Flux::toast('Session rescheduled.'.($sent ? " Updated invite emailed to {$sent} ".str('client')->plural($sent).'.' : ''), variant: 'success');
        }

        $this->finish();
    }

    public function cancel(): void
    {
        if (! $this->trainingSession?->isScheduled()) {
            return;
        }

        $this->authorize('update', $this->trainingSession);

        $sent = app(CancelTrainingSession::class)->handle($this->trainingSession);
        Flux::toast('Session cancelled.'.($sent ? " Cancellation emailed to {$sent} ".str('client')->plural($sent).'.' : ''), variant: 'success');

        $this->finish();
    }

    public function cancelFollowing(): void
    {
        if (! $this->trainingSession?->isScheduled() || ! $this->trainingSession->isInSeries()) {
            return;
        }

        $this->authorize('update', $this->trainingSession);

        $count = app(CancelFollowing::class)->handle($this->trainingSession);
        Flux::toast("Cancelled this and the following sessions ({$count} in total). Invited clients get one cancellation email.", variant: 'success');

        $this->finish();
    }

    /** Close the sheet and let the calendar behind it redraw. */
    private function finish(): void
    {
        $this->mode = 'summary';
        Flux::modal('session-sheet')->close();
        $this->dispatch('session-updated');
    }

    public function render()
    {
        $session = $this->trainingSession?->fresh(['service', 'gym', 'series', 'attendees.client.plan.rates', 'attendees.client.members']);
        $this->trainingSession = $session;

        $scheduled = $session?->isScheduled() ?? false;

        return view('livewire.sessions.session-sheet', [
            'session' => $session,
            'following' => $scheduled && $session->isInSeries() ? $session->followingInSeries()->count() : 0,
            'preview' => $scheduled && $this->mode === 'log' && ! $session->isCover()
                ? $this->previewCharges($session->service, $session->attendees->pluck('client')->keyBy('id'))
                : null,
            'conflicts' => $scheduled && $this->mode === 'edit' ? $this->conflicts($session) : collect(),
        ]);
    }

    /** What the new time would land on top of. A warning only, as on the booking form. */
    private function conflicts(TrainingSession $session): Collection
    {
        if (! strtotime($this->newDate) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $this->newTime)) {
            return collect();
        }

        return SessionConflicts::at("{$this->newDate} {$this->newTime}", (int) $this->newDuration, ignoreId: $session->id);
    }
}
