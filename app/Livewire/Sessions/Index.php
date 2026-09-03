<?php

namespace App\Livewire\Sessions;

use App\Models\SessionSeries;
use App\Models\TrainingSession;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Sessions')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $month = '';

    #[Url]
    public string $series = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $sessions = TrainingSession::query()
            ->with(['service', 'gym', 'series', 'attendees.client'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->series !== '', fn ($q) => $q->where('session_series_id', (int) $this->series))
            ->when($this->month !== '', function ($q) {
                $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
                $q->whereBetween('starts_at', [$start, $start->copy()->endOfMonth()]);
            })
            ->orderByDesc('starts_at')
            ->paginate(25);

        return view('livewire.sessions.index', [
            'sessions' => $sessions,
            'seriesModel' => $this->series !== '' ? SessionSeries::query()->find((int) $this->series) : null,
        ]);
    }
}
