<?php

namespace App\Livewire\Sessions;

use App\Actions\BookSessionSeries;
use App\Actions\CompleteTrainingSession;
use App\Actions\SendSessionInvites;
use App\Actions\SendSessionReceipts;
use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Concerns\PicksAttendees;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Livewire\Sessions\Concerns\PreviewsGymCharge;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Services\CoverFeePricer;
use App\Support\CoverNames;
use App\Support\Recurrence;
use App\Support\SessionConflicts;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * One form, two jobs: "log" records a session that already happened and charges it;
 * "book" schedules a future session and (optionally) emails calendar invites.
 */
class Log extends Component
{
    use PicksAttendees, PreviewsCharges, PreviewsGymCharge;

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

    /** @var array<int, array{attended: bool, override: string, members: array<int, array{attended: bool, override: string}>}> keyed by client id */
    public array $attendees = [];

    /** Covering the gym's own clients: the gym pays her, and no client is charged. */
    public bool $cover = false;

    /** @var array<int, string> free-text names of the gym's clients */
    public array $coverNames = [''];

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
            // The calendar's time grid links an empty slot straight to its time.
            $requestedTime = request()->query('time');
            $this->time = is_string($requestedTime) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $requestedTime) ? $requestedTime : '09:00';
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

    /**
     * Sessions already in the diary that overlap the slot being filled in.
     * A warning only — the trainer decides whether the clash is real.
     *
     * @return Collection<int, TrainingSession>
     */
    public function conflicts(): Collection
    {
        if (! strtotime($this->date) || ! preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $this->time)) {
            return TrainingSession::query()->whereRaw('1 = 0')->get();
        }

        return SessionConflicts::at("{$this->date} {$this->time}", (int) $this->duration_minutes);
    }

    /** How many dates in a repeat land on top of an existing session. */
    public function repeatConflictCount(): int
    {
        if (! $this->isBooking() || ! $this->repeat) {
            return 0;
        }

        try {
            $dates = Recurrence::occurrences($this->date ?: today(), $this->until ?: today(), array_map('intval', $this->weekdays), (int) $this->intervalWeeks);
        } catch (BillingException) {
            return 0;
        }

        $slots = array_map(fn ($date) => $date->format('Y-m-d')." {$this->time}", $dates);

        return count(SessionConflicts::forSlots($slots, (int) $this->duration_minutes));
    }

    public function updatedCover(): void
    {
        if ($this->cover) {
            $this->attendees = [];
            $this->sendReceipts = false;
            $this->sendInvites = false;
            $this->repeat = false;
        }

        $this->resetErrorBag();
    }

    public function addCoverName(): void
    {
        if (count($this->coverNames) < Gym::MAX_PEOPLE) {
            $this->coverNames[] = '';
        }
    }

    public function removeCoverName(int $index): void
    {
        unset($this->coverNames[$index]);
        $this->coverNames = array_values($this->coverNames) ?: [''];
    }

    /** @return array<string, mixed> */
    public function coverPreview(): array
    {
        $gym = $this->gym_id !== '' ? Gym::query()->find((int) $this->gym_id) : null;

        if (! $gym) {
            return ['people' => count(CoverNames::clean($this->coverNames)), 'gym' => null, 'error' => 'Pick the gym you are covering for.'];
        }

        $names = CoverNames::clean($this->coverNames);

        $preview = app(CoverFeePricer::class)->preview($gym, $names, auth()->user()->effectiveGstRate());

        return $preview + [
            'gym' => $gym,
            // Only a missing rate is fixed in Settings; an empty form just needs a name.
            'fix_in_settings' => $names !== [] && $preview['error'] !== null,
        ];
    }

    public function updatedServiceId(): void
    {
        if ($service = $this->services()->firstWhere('id', (int) $this->service_id)) {
            $this->duration_minutes = (string) $service->duration_minutes;
        }
    }

    public function save(bool $complete = true): void
    {
        $this->authorize('create', TrainingSession::class);

        $this->validate([
            'service_id' => ['required', Rule::exists('services', 'id')->where('user_id', auth()->id())],
            // A cover session must name its gym even when there is only one: the credit
            // has to land on some gym's statement.
            'gym_id' => [Rule::requiredIf($this->cover || Gym::query()->active()->exists()), 'nullable', Rule::exists('gyms', 'id')->where('user_id', auth()->id())],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'coverNames' => [Rule::requiredIf($this->cover), 'array'],
            'coverNames.*' => ['nullable', 'string', 'max:'.CoverNames::MAX_LENGTH],
            'attendees' => [Rule::requiredIf(! $this->cover), 'array', $this->cover ? 'max:0' : 'min:1'],
            'attendees.*.attended' => ['boolean'],
            'attendees.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'attendees.*.members' => ['array', 'max:'.Client::MAX_MEMBERS],
            'attendees.*.members.*.attended' => ['boolean'],
            'attendees.*.members.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
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

        if ($this->cover) {
            $this->saveCover($complete);

            return;
        }

        $clients = Client::query()->with('plan', 'members')->whereIn('id', array_keys($this->attendees))->get()->keyBy('id');

        if ($clients->count() !== count($this->attendees)) {
            $this->addError('attendees', 'One of the selected clients could not be found.');

            return;
        }

        foreach ($this->attendees as $clientId => $state) {
            $client = $clients[$clientId];

            if ($client->isOnFamilyPlan() && ! $this->stateAttends($client, $state)) {
                $this->addError('attendees', "Tick which {$client->full_name} members are attending.");

                return;
            }
        }

        if ($this->isBooking() && $this->repeat && ! $complete) {
            $this->bookSeries();

            return;
        }

        try {
            $session = DB::transaction(function () use ($complete, $clients) {
                $session = TrainingSession::create([
                    'service_id' => (int) $this->service_id,
                    'gym_id' => $this->gym_id !== '' ? (int) $this->gym_id : null,
                    'starts_at' => "{$this->date} {$this->time}:00",
                    'duration_minutes' => (int) $this->duration_minutes,
                    'status' => SessionStatus::Scheduled,
                    'notes' => $this->notes ?: null,
                ]);

                foreach ($this->attendees as $clientId => $state) {
                    $client = $clients[$clientId];

                    $attendee = $session->attendees()->create([
                        'client_id' => $clientId,
                        'attended' => $this->stateAttends($client, $state),
                        'price_override' => ! $client->isOnFamilyPlan() && ($state['override'] ?? '') !== ''
                            ? round((float) $state['override'], 2)
                            : null,
                    ]);

                    if ($client->isOnFamilyPlan()) {
                        $attendee->setRelation('client', $client);
                        $attendee->syncMembers($this->memberSelection($state));
                    }
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

    /**
     * A cover session carries no attendees and posts nothing to a wallet: the money is
     * owed by the gym, and it is worked out from the names when the session completes.
     */
    private function saveCover(bool $complete): void
    {
        $names = CoverNames::clean($this->coverNames);

        if ($names === []) {
            $this->addError('coverNames', 'Add the name of at least one person you trained.');

            return;
        }

        $gym = Gym::query()->findOrFail((int) $this->gym_id);

        if ($gym->coverRateFor(count($names)) === null) {
            $this->addError('coverNames', "No cover rate is set for {$gym->name}. Add what they pay you in Settings → Gyms.");

            return;
        }

        try {
            $session = DB::transaction(function () use ($complete, $names) {
                $session = TrainingSession::create([
                    'service_id' => (int) $this->service_id,
                    'gym_id' => (int) $this->gym_id,
                    'gym_cover' => true,
                    'cover_names' => $names,
                    'starts_at' => "{$this->date} {$this->time}:00",
                    'duration_minutes' => (int) $this->duration_minutes,
                    'status' => SessionStatus::Scheduled,
                    'notes' => $this->notes ?: null,
                ]);

                return $complete ? app(CompleteTrainingSession::class)->handle($session) : $session;
            });
        } catch (BillingException $e) {
            $this->addError('coverNames', $e->getMessage());

            return;
        }

        // Nobody to email: these are the gym's clients, and it books them itself.
        Flux::toast(
            $complete
                ? 'Cover session logged. '.money($session->coverTotal())." credited to you on {$gym->name}'s statement."
                : 'Cover session booked.',
            variant: 'success',
        );
        $this->redirectRoute('sessions.show', $session, navigate: true);
    }

    private function bookSeries(): void
    {
        $attendees = [];
        foreach ($this->attendees as $clientId => $state) {
            $attendees[$clientId] = [
                'price_override' => ($state['override'] ?? '') !== '' ? round((float) $state['override'], 2) : null,
                'members' => $this->memberSelection($state),
            ];
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
        $this->redirectRoute('sessions.calendar', ['view' => 'month', 'date' => $this->date], navigate: true);
    }

    private function services()
    {
        return Service::query()->where('active', true)->orderBy('name')->get();
    }

    public function render()
    {
        $selected = Client::query()->with('plan.rates', 'members')->whereIn('id', array_keys($this->attendees))->get()->keyBy('id');
        $service = Service::query()->find((int) $this->service_id);
        $gyms = Gym::query()->active()->orderBy('name')->get();
        $preview = $this->previewCharges($service, $selected);

        return view('livewire.sessions.log', [
            'services' => $this->services(),
            'gyms' => $gyms,
            'candidates' => $this->candidateClients(),
            'selected' => $selected,
            'preview' => $preview,
            'gymCharge' => $this->previewGymCharge($gyms->firstWhere('id', (int) $this->gym_id), $preview, $this->duration_minutes),
            'coverGyms' => Gym::query()->active()->get()->filter->coversSessions(),
            'coverPreview' => $this->cover ? $this->coverPreview() : null,
            'repeatPreview' => $this->isBooking() && $this->repeat ? $this->repeatPreview() : null,
            'conflicts' => $this->conflicts(),
            'repeatConflicts' => $this->repeatConflictCount(),
            'intervals' => Recurrence::INTERVALS,
            'weekdayNames' => Recurrence::WEEKDAYS,
        ])->title($this->isBooking() ? 'Book session' : 'Log session');
    }
}
