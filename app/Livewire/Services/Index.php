<?php

namespace App\Livewire\Services;

use App\Models\Service;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Services')]
class Index extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $duration_minutes = '60';

    public bool $active = true;

    public function create(): void
    {
        $this->authorize('create', Service::class);
        $this->resetForm();
        Flux::modal('service-form')->show();
    }

    public function edit(int $id): void
    {
        $service = Service::query()->findOrFail($id);
        $this->authorize('update', $service);

        $this->editingId = $service->id;
        $this->name = $service->name;
        $this->duration_minutes = (string) $service->duration_minutes;
        $this->active = $service->active;
        $this->resetErrorBag();
        Flux::modal('service-form')->show();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'active' => ['boolean'],
        ]);

        if ($this->editingId) {
            $service = Service::query()->findOrFail($this->editingId);
            $this->authorize('update', $service);
            $service->update($data);
            Flux::toast('Service updated.', variant: 'success');
        } else {
            $this->authorize('create', Service::class);
            Service::create($data);
            Flux::toast('Service added.', variant: 'success');
        }

        $this->resetForm();
        Flux::modal('service-form')->close();
    }

    public function delete(int $id): void
    {
        $service = Service::query()->findOrFail($id);
        $this->authorize('delete', $service);

        if ($service->trainingSessions()->exists()) {
            Flux::toast('This service has sessions logged against it. Mark it inactive instead.', variant: 'warning');

            return;
        }

        try {
            $service->delete();
        } catch (QueryException) {
            Flux::toast('This service is in use and cannot be deleted. Mark it inactive instead.', variant: 'danger');

            return;
        }

        Flux::toast('Service deleted.', variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'duration_minutes', 'active');
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.services.index', [
            'services' => Service::query()->withCount('trainingSessions')->orderByDesc('active')->orderBy('name')->get(),
        ]);
    }
}
