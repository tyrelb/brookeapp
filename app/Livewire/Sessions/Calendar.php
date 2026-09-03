<?php

namespace App\Livewire\Sessions;

use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Calendar')]
class Calendar extends Component
{
    #[Url]
    public string $date = ''; // any date inside the visible month / week

    #[Url]
    public string $view = 'month'; // month | week

    public function mount(): void
    {
        if ($this->date === '' || ! strtotime($this->date)) {
            $this->date = today()->toDateString();
        }

        if (! in_array($this->view, ['month', 'week'], true)) {
            $this->view = 'month';
        }
    }

    public function previous(): void
    {
        $this->date = $this->anchor()->sub($this->view === 'month' ? '1 month' : '1 week')->toDateString();
    }

    public function next(): void
    {
        $this->date = $this->anchor()->add($this->view === 'month' ? '1 month' : '1 week')->toDateString();
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['month', 'week'], true) ? $view : 'month';
    }

    private function anchor(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date);
    }

    public function render()
    {
        $anchor = $this->anchor();

        if ($this->view === 'week') {
            $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);
            $end = $start->addDays(6);
            $title = $start->format('M j').' – '.$end->format($start->month === $end->month ? 'j, Y' : 'M j, Y');
        } else {
            $start = $anchor->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
            $end = $anchor->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
            $title = $anchor->format('F Y');
        }

        $sessions = TrainingSession::query()
            ->with(['service', 'attendees.client'])
            ->whereBetween('starts_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (TrainingSession $s) => $s->starts_at->toDateString());

        $days = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $days[] = $d;
        }

        return view('livewire.sessions.calendar', [
            'title' => $title,
            'days' => $days,
            'sessions' => $sessions,
            'month' => $anchor->month,
            'weeks' => array_chunk($days, 7),
        ]);
    }
}
