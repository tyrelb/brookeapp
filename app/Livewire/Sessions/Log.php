<?php

namespace App\Livewire\Sessions;

use App\Actions\CompleteTrainingSession;
use App\Actions\SendSessionInvites;
use App\Actions\SendSessionReceipts;
use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Models\Client;
use App\Models\Service;
use App\Models\TrainingSession;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * One form, two jobs: "log" records a session that already happened and charges it;
 * "book" schedules a future session and (optionally) emails calendar invites.
 */
class Log extends Component
{
    use PreviewsCharges;

    public string $mode = 'log'; // log | book

    public string $service_id = '';

    public string $date = '';

    public string $time = '';

    public string $duration_minutes = '60';

    public string $notes = '';

    public string $clientSearch = '';

    /** @var array<int, array{attended: bool, override: string}> keyed by client id */
    public array $attendees = [];

    public bool $sendInvites = true;

    public bool $sendReceipts = false;

    public function mount(?string $mode = null): void
    {
        $this->authorize('create', TrainingSession::class);

        $this->mode = $mode ?? (request()->routeIs('sessions.book') ? 'book' : 'log');
        $trainer = auth()->user();
        $this->sendInvites = (bool) $trainer->notify_on_booking;
        $this->sendReceipts = (bool) $trainer->notify_on_completion;

        if ($this->isBooking()) {
            $requested = request()->query('date');
            $this->date = $requested && strtotime($requested) ? $requested : today()->addDay()->toDateString();
            $this->time = '09:00';
        } else {
            $this->date = today()->toDateString();
            $this->time = now()->subHour()->format('H:00');
        }

        $first = $this->services()->first();
        if ($first) {
            $this->service_id = (string) $first->id;
            $this->duration_minutes = (string) $first->duration_minutes;
        }

        if ($clientId = (int) request()->query('client')) {
            $this->addClient($clientId);
        }
    }

    public function isBooking(): bool
    {
        return $this->mode === 'book';
    }

    public function updatedServiceId(): void
    {
        if ($service = $this->services()->firstWhere('id', (int) $this->service_id)) {
            $this->duration_minutes = (string) $service->duration_minutes;
        }
    }

    public function addClient(int $clientId): void
    {
        $client = Client::query()->find($clientId);

        if (! $client || isset($this->attendees[$clientId])) {
            return;
        }

        $this->attendees[$clientId] = ['attended' => true, 'override' => ''];
        $this->clientSearch = '';
    }

    public function removeClient(int $clientId): void
    {
        unset($this->attendees[$clientId]);
    }

    public function save(bool $complete = true): void
    {
        $this->authorize('create', TrainingSession::class);

        $this->validate([
            'service_id' => ['required', Rule::exists('services', 'id')->where('user_id', auth()->id())],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attendees' => ['required', 'array', 'min:1'],
            'attendees.*.attended' => ['boolean'],
            'attendees.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'attendees.required' => 'Add at least one client.',
            'attendees.min' => 'Add at least one client.',
        ]);

        $clientIds = Client::query()->whereIn('id', array_keys($this->attendees))->pluck('id')->all();

        if (count($clientIds) !== count($this->attendees)) {
            $this->addError('attendees', 'One of the selected clients could not be found.');

            return;
        }

        try {
            $session = DB::transaction(function () use ($complete) {
                $session = TrainingSession::create([
                    'service_id' => (int) $this->service_id,
                    'starts_at' => "{$this->date} {$this->time}:00",
                    'duration_minutes' => (int) $this->duration_minutes,
                    'status' => SessionStatus::Scheduled,
                    'notes' => $this->notes ?: null,
                ]);

                foreach ($this->attendees as $clientId => $state) {
                    $session->attendees()->create([
                        'client_id' => $clientId,
                        'attended' => (bool) $state['attended'],
                        'price_override' => ($state['override'] ?? '') !== '' ? round((float) $state['override'], 2) : null,
                    ]);
                }

                if ($complete) {
                    $session = app(CompleteTrainingSession::class)->handle($session);
                }

                return $session;
            });
        } catch (BillingException $e) {
            $this->addError('attendees', $e->getMessage());

            return;
        }

        $message = $complete ? 'Session logged and attendees charged.' : 'Session booked.';

        if ($complete && $this->sendReceipts) {
            $sent = app(SendSessionReceipts::class)->handle($session);
            $message .= $sent ? " Receipt emailed to {$sent} ".str('client')->plural($sent).'.' : ' No attendee has an email address, so no receipts were sent.';
        }

        if (! $complete && $this->sendInvites) {
            $sent = app(SendSessionInvites::class)->handle($session);
            $message .= $sent ? " Calendar invite emailed to {$sent} ".str('client')->plural($sent).'.' : ' No attendee has an email address, so no invites were sent.';
        }

        Flux::toast($message, variant: 'success');
        $this->redirectRoute('sessions.show', $session, navigate: true);
    }

    private function services()
    {
        return Service::query()->where('active', true)->orderBy('name')->get();
    }

    public function render()
    {
        $selected = Client::query()->with('plan.rates')->whereIn('id', array_keys($this->attendees))->get()->keyBy('id');
        $service = Service::query()->find((int) $this->service_id);

        $candidates = Client::query()->active()->with('plan')
            ->whereNotIn('id', array_keys($this->attendees))
            ->search($this->clientSearch)
            ->orderBy('first_name')->orderBy('last_name')
            ->limit($this->clientSearch === '' ? 12 : 25)
            ->get();

        return view('livewire.sessions.log', [
            'services' => $this->services(),
            'candidates' => $candidates,
            'selected' => $selected,
            'preview' => $this->previewCharges($service, $selected),
        ])->title($this->isBooking() ? 'Book session' : 'Log session');
    }
}
