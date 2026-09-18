<?php

namespace App\Livewire\Sessions;

use App\Models\TrainingSession;
use App\Support\CalendarGrid;
use App\Support\SessionConflicts;
use Carbon\CarbonImmutable;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Calendar')]
class Calendar extends Component
{
    private const VIEWS = ['day', 'week', 'month', 'list'];

    /** The list shows this many weeks at a time, and "Show more" adds as many again. */
    private const LIST_WEEKS = 2;

    private const MAX_LIST_WEEKS = 12;

    #[Url]
    public string $date = ''; // any date inside the visible month / week, or the visible day itself

    #[Url]
    public string $view = 'day'; // day | week | month | list

    public int $listWeeks = self::LIST_WEEKS;

    public function mount(): void
    {
        $this->date = ($this->date !== '' && strtotime($this->date))
            ? CarbonImmutable::parse($this->date)->toDateString()
            : today()->toDateString();

        // A phone opens on the list; the time grid needs a wider screen. The cookie is
        // set by partials/head, and a view picked in the URL always wins.
        if (! request()->query->has('view') && request()->cookie('narrow_screen') === '1') {
            $this->view = 'list';
        }

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'day';
        }
    }

    public function previous(): void
    {
        $this->date = $this->anchor()->sub($this->step())->toDateString();
        $this->listWeeks = self::LIST_WEEKS;
    }

    public function next(): void
    {
        $this->date = $this->anchor()->add($this->step())->toDateString();
        $this->listWeeks = self::LIST_WEEKS;
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
        $this->listWeeks = self::LIST_WEEKS;
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'day';
        $this->listWeeks = self::LIST_WEEKS;
    }

    /** Stretch the list further ahead instead of paging past what is already on screen. */
    public function showMore(): void
    {
        $this->listWeeks = min(self::MAX_LIST_WEEKS, $this->listWeeks + self::LIST_WEEKS);
    }

    /** The session sheet changed something: draw the calendar again. */
    #[On('session-updated')]
    public function refreshSessions(): void {}

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
            'list' => self::LIST_WEEKS.' weeks',
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
        } elseif ($this->view === 'list') {
            $start = $anchor;
            $end = $anchor->addDays($this->listWeeks * 7 - 1);
            $title = $start->format($start->year === $end->year ? 'M j' : 'M j, Y').' – '.$end->format('M j, Y');
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

        // Day and week draw each session at its time of day, side by side where they overlap.
        $placements = [];
        if (in_array($this->view, ['day', 'week'], true)) {
            foreach ($sessions as $daySessions) {
                $placements += CalendarGrid::place($daySessions);
            }
        }

        return view('livewire.sessions.calendar', [
            'title' => $title,
            'days' => $days,
            'sessions' => $sessions,
            'conflicts' => $conflicts,
            'conflictCount' => count(array_intersect_key($conflicts, $sessions->flatten()->keyBy('id')->all())),
            'month' => $anchor->month,
            'weeks' => array_chunk($days, 7),
            // The list skips empty days, as a phone's agenda does, but always shows today.
            'listDays' => array_values(array_filter($days, fn (CarbonImmutable $d) => $sessions->has($d->toDateString()) || $d->isToday())),
            'canShowMore' => $this->listWeeks < self::MAX_LIST_WEEKS,
            'placements' => $placements,
            'halfHours' => CalendarGrid::halfHours(), // not "slots": Livewire reserves that view variable
            // Open the time grid at the start of the working day, or earlier if a session starts before then.
            'scrollHour' => min(7, $sessions->flatten()->min(fn (TrainingSession $s) => $s->starts_at->hour) ?? 7),
            'nowOffset' => CalendarGrid::offset(now()),
        ]);
    }
}
