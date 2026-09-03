<?php

namespace App\Livewire\Sessions;

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
            ->with(['service', 'gym', 'attendees.client'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->month !== '', function ($q) {
                $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
                $q->whereBetween('starts_at', [$start, $start->copy()->endOfMonth()]);
            })
            ->orderByDesc('starts_at')
            ->paginate(25);

        return view('livewire.sessions.index', ['sessions' => $sessions]);
    }
}
