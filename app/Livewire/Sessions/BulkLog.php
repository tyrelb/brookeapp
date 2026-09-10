<?php

namespace App\Livewire\Sessions;

use App\Actions\LogSessionsInBulk;
use App\Actions\SendSessionReceipts;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Concerns\PicksAttendees;
use App\Livewire\Sessions\Concerns\PreviewsCharges;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Support\DateList;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Logs a backlog of sessions in one pass: one set-up (service, gym, clients),
 * many dates. Built for catching up after a busy month and for importing history
 * from a spreadsheet, so dates can be clicked on a calendar or pasted in bulk.
 */
#[Title('Bulk log sessions')]
class BulkLog extends Component
{
    use PicksAttendees, PreviewsCharges;

    public string $service_id = '';

    public string $gym_id = '';

    /** True once the trainer picked a gym themselves, so adding clients stops changing it. */
    public bool $gymChosen = false;

    public string $time = LogSessionsInBulk::DEFAULT_TIME;

    public string $duration_minutes = '60';

    public string $notes = '';

    public string $clientSearch = '';

    /** @var array<int, array{attended: bool, override: string, members: array<int, array{attended: bool, override: string}>}> keyed by client id */
    public array $attendees = [];

    public bool $sendReceipts = false;

    /** @var list<string> the sessions to create, one per date (Y-m-d) */
    public array $dates = [];

    /** Any date inside the month shown in the picker. */
    public string $monthAnchor = '';

    public string $paste = '';

    public function mount(): void
    {
        $this->authorize('create', TrainingSession::class);

        $this->monthAnchor = today()->startOfMonth()->toDateString();
        $this->gym_id = (string) (auth()->user()->defaultGym()?->id ?? '');

        if ($first = $this->services()->first()) {
            $this->service_id = (string) $first->id;
            $this->duration_minutes = (string) $first->duration_minutes;
        }

        if ($clientId = (int) request()->query('client')) {
            $this->addClient($clientId);
        }
    }

    public function updatedServiceId(): void
    {
        if ($service = $this->services()->firstWhere('id', (int) $this->service_id)) {
            $this->duration_minutes = (string) $service->duration_minutes;
        }
    }

    public function toggleDate(string $date): void
    {
        if (! strtotime($date)) {
            return;
        }

        $date = CarbonImmutable::parse($date)->toDateString();

        if (in_array($date, $this->dates, true)) {
            $this->dates = array_values(array_diff($this->dates, [$date]));

            return;
        }

        if (count($this->dates) >= LogSessionsInBulk::MAX_SESSIONS) {
            Flux::toast($this->capReached(), variant: 'warning');

            return;
        }

        $this->dates[] = $date;
        sort($this->dates);
        $this->resetValidation('dates');
    }

    public function clearDates(): void
    {
        $this->dates = [];
    }

    public function previousMonth(): void
    {
        $this->monthAnchor = CarbonImmutable::parse($this->monthAnchor)->subMonth()->startOfMonth()->toDateString();
    }

    public function nextMonth(): void
    {
        $this->monthAnchor = CarbonImmutable::parse($this->monthAnchor)->addMonth()->startOfMonth()->toDateString();
    }

    /** Pull dates out of a pasted spreadsheet column or list and add them to the selection. */
    public function addPastedDates(): void
    {
        $parsed = DateList::parse($this->paste);

        if ($parsed['dates'] === []) {
            $this->addError('paste', 'No dates found. Use a full date with the year, like 2026-06-03 or Jun 3, 2026.');

            return;
        }

        $before = count($this->dates);
        $this->dates = array_values(array_unique(array_merge($this->dates, $parsed['dates'])));
        sort($this->dates);

        $overflow = max(0, count($this->dates) - LogSessionsInBulk::MAX_SESSIONS);
        if ($overflow > 0) {
            $this->dates = array_slice($this->dates, 0, LogSessionsInBulk::MAX_SESSIONS);
        }

        $added = count($this->dates) - $before;
        $this->paste = '';
        $this->resetValidation();
        $this->monthAnchor = CarbonImmutable::parse($this->dates[0])->startOfMonth()->toDateString();

        $message = "Added {$added} ".str('date')->plural($added).'.';
        $message .= $overflow > 0 ? ' '.$this->capReached() : '';
        $message .= $parsed['ignored'] !== [] ? " Skipped: {$this->listIgnored($parsed['ignored'])}." : '';

        Flux::toast($message, variant: $overflow > 0 || $parsed['ignored'] !== [] ? 'warning' : 'success');
    }

    public function save(bool $complete = true): void
    {
        $this->authorize('create', TrainingSession::class);

        $this->validate([
            'service_id' => ['required', Rule::exists('services', 'id')->where('user_id', auth()->id())],
            'gym_id' => [Rule::requiredIf(Gym::query()->active()->exists()), 'nullable', Rule::exists('gyms', 'id')->where('user_id', auth()->id())],
            'time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'dates' => ['required', 'array', 'min:1', 'max:'.LogSessionsInBulk::MAX_SESSIONS],
            'dates.*' => ['date'],
            'attendees' => ['required', 'array', 'min:1'],
            'attendees.*.attended' => ['boolean'],
            'attendees.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'attendees.*.members' => ['array', 'max:'.Client::MAX_MEMBERS],
            'attendees.*.members.*.attended' => ['boolean'],
            'attendees.*.members.*.override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'gym_id.required' => 'Pick the gym these sessions are at so they show on the gym usage report.',
            'dates.required' => 'Pick at least one date.',
            'dates.min' => 'Pick at least one date.',
            'dates.max' => 'Log at most '.LogSessionsInBulk::MAX_SESSIONS.' sessions at a time.',
            'attendees.required' => 'Add at least one client.',
            'attendees.min' => 'Add at least one client.',
        ]);

        $clients = Client::query()->with('plan', 'members')->whereIn('id', array_keys($this->attendees))->get()->keyBy('id');

        if ($clients->count() !== count($this->attendees)) {
            $this->addError('attendees', 'One of the selected clients could not be found.');

            return;
        }

        $attendees = [];
        foreach ($this->attendees as $clientId => $state) {
            $client = $clients[$clientId];

            if ($client->isOnFamilyPlan() && ! $this->stateAttends($client, $state)) {
                $this->addError('attendees', "Tick which {$client->full_name} members are attending.");

                return;
            }

            $attendees[(int) $clientId] = [
                'attended' => $this->stateAttends($client, $state),
                'price_override' => ! $client->isOnFamilyPlan() && ($state['override'] ?? '') !== ''
                    ? round((float) $state['override'], 2)
                    : null,
                'members' => $this->memberSelection($state),
            ];
        }

        try {
            $sessions = app(LogSessionsInBulk::class)->handle([
                'user_id' => auth()->id(),
                'service_id' => (int) $this->service_id,
                'gym_id' => $this->gym_id !== '' ? (int) $this->gym_id : null,
                'time' => $this->time,
                'duration_minutes' => (int) $this->duration_minutes,
                'notes' => $this->notes ?: null,
            ], $this->dates, $attendees, $complete);
        } catch (BillingException $e) {
            $this->addError('dates', $e->getMessage());

            return;
        }

        $count = count($sessions);
        $message = $count.' '.str('session')->plural($count).' '.($complete ? 'logged and charged.' : 'saved as scheduled.');

        if ($complete && $this->sendReceipts) {
            $sent = 0;
            foreach ($sessions as $session) {
                $sent += app(SendSessionReceipts::class)->handle($session);
            }
            $message .= $sent ? " {$sent} ".str('receipt')->plural($sent).' emailed.' : ' No attendee has an email address, so no receipts were sent.';
        }

        Flux::toast($message, variant: 'success');
        $this->redirectRoute('sessions.calendar', ['view' => 'month', 'date' => $this->dates[0]], navigate: true);
    }

    private function capReached(): string
    {
        return 'That is the limit of '.LogSessionsInBulk::MAX_SESSIONS.' sessions per batch. Log these, then start another batch.';
    }

    /** @param  list<string>  $ignored */
    private function listIgnored(array $ignored): string
    {
        $shown = array_slice($ignored, 0, 3);
        $rest = count($ignored) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " and {$rest} more" : '');
    }

    private function services()
    {
        return Service::query()->where('active', true)->orderBy('name')->get();
    }

    public function render()
    {
        $selected = Client::query()->with('plan.rates', 'members')->whereIn('id', array_keys($this->attendees))->get()->keyBy('id');
        $preview = $this->previewCharges(Service::query()->find((int) $this->service_id), $selected);

        $anchor = CarbonImmutable::parse($this->monthAnchor ?: today());
        $start = $anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
        $end = $anchor->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = $day;
        }

        return view('livewire.sessions.bulk-log', [
            'services' => $this->services(),
            'gyms' => Gym::query()->active()->orderBy('name')->get(),
            'candidates' => $this->candidateClients(),
            'selected' => $selected,
            'preview' => $preview,
            'perSession' => $preview['total'],
            'grandTotal' => round($preview['total'] * count($this->dates), 2),
            'weeks' => array_chunk($days, 7),
            'month' => $anchor->month,
            'monthLabel' => $anchor->format('F Y'),
            'alreadyLogged' => $this->alreadyLogged(),
            'maxSessions' => LogSessionsInBulk::MAX_SESSIONS,
        ]);
    }

    /**
     * Dates in the selection where one of these clients already has a session,
     * so an import does not silently double-charge anyone.
     *
     * @return array<string, true>
     */
    private function alreadyLogged(): array
    {
        if ($this->dates === [] || $this->attendees === []) {
            return [];
        }

        $sorted = $this->dates;
        sort($sorted);

        return TrainingSession::query()
            ->whereBetween('starts_at', [
                CarbonImmutable::parse($sorted[0])->startOfDay(),
                CarbonImmutable::parse(end($sorted))->endOfDay(),
            ])
            ->whereHas('attendees', fn ($q) => $q->whereIn('client_id', array_keys($this->attendees)))
            ->pluck('starts_at')
            ->mapWithKeys(fn ($startsAt) => [$startsAt->toDateString() => true])
            ->intersectByKeys(array_flip($this->dates))
            ->all();
    }
}
