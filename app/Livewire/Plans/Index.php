<?php

namespace App\Livewire\Plans;

use App\Models\Plan;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Plans & pricing')]
class Index extends Component
{
    public function delete(int $id): void
    {
        $plan = Plan::query()->withCount('clients')->findOrFail($id);
        $this->authorize('delete', $plan);

        if ($plan->clients_count > 0) {
            Flux::toast('Clients are still on this plan. Move them first, or mark the plan inactive.', variant: 'warning');

            return;
        }

        $plan->delete();
        Flux::toast('Plan deleted.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.plans.index', [
            'plans' => Plan::query()->withCount('clients')->with('rates.service')->orderByDesc('active')->orderBy('name')->get(),
        ]);
    }
}
