<?php

namespace App\Livewire\Sessions;

use App\Models\TrainingSession;
use App\Support\SessionConflicts;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Calendar')]
class Calendar extends Component
{
    private const VIEWS = ['day', 'week', 'month'];

    #[Url]
    public string $date = ''; // any date inside the visible month / week, or the visible day itself

    #[Url]
    public string $view = 'day'; // day | week | month

    public function mount(): void
    {
        $this->date = ($this->date !== '' && strtotime($this->date))
            ? CarbonImmutable::parse($this->date)->toDateString()
            : today()->toDateString();

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'day';
        }
    }

    public function previous(): void
    {
        $this->date = $this->anchor()->sub($this->step())->toDateString();
    }

    public function next(): void
    {
        $this->date = $this->anchor()->add($this->step())->toDateString();
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'day';
    }

    /** Jump to the day view for one date (from a day cell in the month or week view). */
    public function showDay(string $date): void
    {
        if (! strtotime($date)) {
            return;
        }

        $this->date = CarbonImmutable::parse($date)->toDateString();
        $this->view = 'day';
    }

    private function step(): string
    {
        return match ($this->view) {
            'day' => '1 day',
            'week' => '1 week',
            default => '1 month',
        };
    }

    private function anchor(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date);
    }

    public function render()
    {
        $anchor = $this->anchor();

        if ($this->view === 'day') {
            $start = $end = $anchor;
            $title = $anchor->format('l, F j, Y');
        } elseif ($this->view === 'week') {
            $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);
            $end = $start->addDays(6);
            $title = $start->format('M j').' – '.$end->format($start->month === $end->month ? 'j, Y' : 'M j, Y');
        } else {
            $start = $anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
            $end = $anchor->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
            $title = $anchor->format('F Y');
        }

        // A day either side of the visible range so a session that runs late still
        // clashes with the one it overlaps into.
        $loaded = TrainingSession::query()
            ->with(['service', 'gym', 'attendees.client'])
            ->whereBetween('starts_at', [$start->subDay()->startOfDay(), $end->addDay()->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        $conflicts = SessionConflicts::within($loaded);

        $sessions = $loaded
            ->filter(fn (TrainingSession $s) => $s->starts_at->between($start->startOfDay(), $end->endOfDay()))
            ->groupBy(fn (TrainingSession $s) => $s->starts_at->toDateString());

        $days = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $days[] = $d;
        }

        return view('livewire.sessions.calendar', [
            'title' => $title,
            'days' => $days,
            'sessions' => $sessions,
            'conflicts' => $conflicts,
            'conflictCount' => count(array_intersect_key($conflicts, $sessions->flatten()->keyBy('id')->all())),
            'month' => $anchor->month,
            'weeks' => array_chunk($days, 7),
        ]);
    }
}
