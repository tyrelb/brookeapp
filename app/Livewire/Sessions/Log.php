<?php

namespace App\Livewire\Sessions;

use App\Actions\BookSessionSeries;
use App\Actions\CompleteTrainingSession;
use App\Actions\SendSessionInvites;
use App\Actions\SendSessionReceipts;
use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Support\Recurrence;
use Carbon\Carbon;
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

    public string $gym_id = '';

    /** True once the trainer picked a gym themselves, so adding clients stops changing it. */
    public bool $gymChosen = false;

    public string $date = '';

    public string $time = '';

    public string $duration_minutes = '60';

    public string $notes = '';

    public string $clientSearch = '';

    /** @var array<int, array{attended: bool, override: string}> keyed by client id */
    public array $attendees = [];

    public bool $sendInvites = true;

    public bool $sendReceipts = false;

    // Repeat (book mode only)
    public bool $repeat = false;

    public string $intervalWeeks = '1';

    /** @var list<int> ISO weekdays */
    public array $weekdays = [];

    public string $until = '';

    /** True once the trainer picked weekdays themselves, so changing the date stops resetting them. */
    public bool $weekdaysChosen = false;

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
            $this->until = Carbon::parse($this->date)->addWeeks(8)->toDateString();
            $this->weekdays = [Carbon::parse($this->date)->dayOfWeekIso];
        } else {
            $this->date = today()->toDateString();
            $this->time = now()->subHour()->format('H:00');
        }

        $this->gym_id = (string) ($trainer->defaultGym()?->id ?? '');

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

    public function updatedDate(): void
    {
        if ($this->isBooking() && ! $this->weekdaysChosen && strtotime($this->date)) {
            $this->weekdays = [Carbon::parse($this->date)->dayOfWeekIso];
        }
    }

    public function updatedWeekdays(): void
    {
        $this->weekdaysChosen = true;
    }

    /**
     * @return array{count:int, last:?string, description:string, error:?string}
     */
    public function repeatPreview(): array
    {
        try {
            $dates = Recurrence::occurrences($this->date ?: today(), $this->until ?: today(), array_map('intval', $this->weekdays), (int) $this->intervalWeeks);

            return [
                'count' => count($dates),
                'last' => $dates !== [] ? end($dates)->format('D M j, Y') : null,
                'description' => Recurrence::describe((int) $this->intervalWeeks, array_map('intval', $this->weekdays), $this->until ?: null),
                'error' => $dates === [] ? 'No dates match that pattern before the end date.' : null,
            ];
        } catch (BillingException $e) {
            return ['count' => 0, 'last' => null, 'description' => '', 'error' => $e->getMessage()];
        }
    }

    public function updatedGymId(): void
    {
        $this->gymChosen = true;
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

        // The first client's usual gym wins unless the trainer already chose one.
        if (! $this->gymChosen && $client->gym_id && Gym::query()->active()->whereKey($client->gym_id)->exists()) {
            $this->gym_id = (string) $client->gym_id;
        }
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
            'gym_id' => [Rule::requiredIf(Gym::query()->active()->exists()), 'nullable', Rule::exists('gyms', 'id')->where('user_id', auth()->id())],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attendees' => ['required', 'array', 'min:1'],
            'attendees.*.attended' => ['boolean'],
            'attendees.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'repeat' => ['boolean'],
            'until' => [Rule::requiredIf($this->isBooking() && $this->repeat), 'nullable', 'date', 'after_or_equal:date'],
            'intervalWeeks' => [Rule::in(['1', '2', '4'])],
            'weekdays' => [Rule::requiredIf($this->isBooking() && $this->repeat), 'array'],
            'weekdays.*' => ['integer', 'min:1', 'max:7'],
        ], [
            'until.required' => 'Repeats need an end date.',
            'until.after_or_equal' => 'The end date must be on or after the start date.',
            'weekdays.required' => 'Choose at least one day of the week.',
            'gym_id.required' => 'Pick the gym this session is at so it shows on the gym usage report.',
            'attendees.required' => 'Add at least one client.',
            'attendees.min' => 'Add at least one client.',
        ]);

        $clientIds = Client::query()->whereIn('id', array_keys($this->attendees))->pluck('id')->all();

        if (count($clientIds) !== count($this->attendees)) {
            $this->addError('attendees', 'One of the selected clients could not be found.');

            return;
        }

        if ($this->isBooking() && $this->repeat && ! $complete) {
            $this->bookSeries();

            return;
        }

        try {
            $session = DB::transaction(function () use ($complete) {
                $session = TrainingSession::create([
                    'service_id' => (int) $this->service_id,
                    'gym_id' => $this->gym_id !== '' ? (int) $this->gym_id : null,
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

    private function bookSeries(): void
    {
        $attendees = [];
        foreach ($this->attendees as $clientId => $state) {
            $attendees[$clientId] = ['price_override' => ($state['override'] ?? '') !== '' ? round((float) $state['override'], 2) : null];
        }

        try {
            $series = app(BookSessionSeries::class)->handle([
                'user_id' => auth()->id(),
                'service_id' => (int) $this->service_id,
                'gym_id' => $this->gym_id !== '' ? (int) $this->gym_id : null,
                'starts_on' => $this->date,
                'ends_on' => $this->until,
                'time' => $this->time,
                'duration_minutes' => (int) $this->duration_minutes,
                'interval_weeks' => (int) $this->intervalWeeks,
                'weekdays' => array_map('intval', $this->weekdays),
                'notes' => $this->notes ?: null,
            ], $attendees, $this->sendInvites);
        } catch (BillingException $e) {
            $this->addError('until', $e->getMessage());

            return;
        }

        $count = $series->sessions()->count();
        $message = "Booked {$count} ".str('session')->plural($count).', '.strtolower($series->describe()).'.';

        if ($this->sendInvites) {
            $withEmail = Client::query()->whereIn('id', array_keys($attendees))->whereNotNull('email')->count();
            $message .= $withEmail ? " One invite with all dates emailed to {$withEmail} ".str('client')->plural($withEmail).'.' : ' No attendee has an email address, so no invites were sent.';
        }

        Flux::toast($message, variant: 'success');
        $this->redirectRoute('sessions.calendar', ['date' => $this->date], navigate: true);
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
            'gyms' => Gym::query()->active()->orderBy('name')->get(),
            'candidates' => $candidates,
            'selected' => $selected,
            'preview' => $this->previewCharges($service, $selected),
            'repeatPreview' => $this->isBooking() && $this->repeat ? $this->repeatPreview() : null,
            'intervals' => Recurrence::INTERVALS,
            'weekdayNames' => Recurrence::WEEKDAYS,
        ])->title($this->isBooking() ? 'Book session' : 'Log session');
    }
}
