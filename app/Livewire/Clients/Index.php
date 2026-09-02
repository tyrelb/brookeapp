<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Models\Plan;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Clients')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $plan = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPlan(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $clients = Client::query()
            ->with('plan')
            ->withBalance()
            ->search($this->search)
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->plan !== '', fn ($q) => $q->where('plan_id', $this->plan))
            ->orderBy('first_name')->orderBy('last_name')
            ->paginate(25);

        return view('livewire.clients.index', [
            'clients' => $clients,
            'plans' => Plan::query()->orderBy('name')->get(),
        ]);
    }
}
