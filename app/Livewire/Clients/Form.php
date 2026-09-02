<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Plan;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Form extends Component
{
    public ?Client $client = null;

    #[Validate('required|string|max:100')]
    public string $first_name = '';

    #[Validate('nullable|string|max:100')]
    public string $last_name = '';

    public string $email = '';

    #[Validate('nullable|string|max:30')]
    public string $phone = '';

    public string $plan_id = '';

    public string $status = 'active';

    #[Validate('nullable|date')]
    public string $started_at = '';

    #[Validate('nullable|string|max:5000')]
    public string $notes = '';

    public function mount(?Client $client = null): void
    {
        if ($client?->exists) {
            $this->authorize('update', $client);
            $this->client = $client;
            $this->first_name = $client->first_name;
            $this->last_name = (string) $client->last_name;
            $this->email = (string) $client->email;
            $this->phone = (string) $client->phone;
            $this->plan_id = (string) $client->plan_id;
            $this->status = $client->status->value;
            $this->started_at = $client->started_at?->toDateString() ?? '';
            $this->notes = (string) $client->notes;
        } else {
            $this->authorize('create', Client::class);
            $this->client = null;
            $this->started_at = today()->toDateString();
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('clients', 'email')
                    ->where('user_id', auth()->id())
                    ->ignore($this->client?->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'plan_id' => ['nullable', Rule::exists('plans', 'id')->where('user_id', auth()->id())],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'started_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['email'] = $data['email'] ?: null;
        $data['plan_id'] = $data['plan_id'] ?: null;
        $data['started_at'] = $data['started_at'] ?: null;
        $data['last_name'] = $data['last_name'] ?: null;

        if ($this->client) {
            $this->client->update($data);
            Flux::toast('Client updated.', variant: 'success');
        } else {
            $this->client = Client::create($data);
            Flux::toast('Client added.', variant: 'success');
        }

        $this->redirectRoute('clients.show', $this->client, navigate: true);
    }

    public function render()
    {
        return view('livewire.clients.form', [
            'plans' => Plan::query()->where('active', true)->orderBy('name')->get(),
        ])->title($this->client ? 'Edit client' : 'Add client');
    }
}
