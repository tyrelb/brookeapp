<?php

namespace App\Livewire\Clients;

use App\Enums\ClientStatus;
use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Plan;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
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

    public string $gym_id = '';

    public string $status = 'active';

    #[Validate('nullable|date')]
    public string $started_at = '';

    #[Validate('nullable|string|max:5000')]
    public string $notes = '';

    /**
     * Family members, in order. Each row is ['id' => ?int, 'name' => string]; a blank
     * name means the slot is unused, and an id means an existing member being renamed.
     *
     * @var array<int, array{id: ?int, name: string}>
     */
    public array $members = [];

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
            $this->gym_id = (string) ($client->gym_id ?? '');
            $this->status = $client->status->value;
            $this->started_at = $client->started_at?->toDateString() ?? '';
            $this->notes = (string) $client->notes;
            $this->members = $client->activeMembers
                ->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])
                ->values()
                ->all();
        } else {
            $this->authorize('create', Client::class);
            $this->client = null;
            $this->started_at = today()->toDateString();
            $this->gym_id = (string) (auth()->user()->defaultGym()?->id ?? '');
        }

        $this->padMembers();
    }

    /** Always leave one empty slot to type into, up to the cap. */
    private function padMembers(): void
    {
        if (count($this->members) < Client::MAX_MEMBERS && ! collect($this->members)->contains(fn ($m) => trim($m['name']) === '')) {
            $this->members[] = ['id' => null, 'name' => ''];
        }
    }

    public function updatedPlanId(): void
    {
        $this->padMembers();
    }

    /** Typing in the last slot opens the next one, so the list grows as you go. */
    public function updatedMembers(): void
    {
        $this->padMembers();
    }

    public function addMember(): void
    {
        if (count($this->members) < Client::MAX_MEMBERS) {
            $this->members[] = ['id' => null, 'name' => ''];
        }
    }

    public function removeMember(int $index): void
    {
        unset($this->members[$index]);
        $this->members = array_values($this->members);
        $this->padMembers();
    }

    /** True when the plan currently chosen in the form is a family plan. */
    public function isFamilyPlan(): bool
    {
        return $this->plan_id !== ''
            && (Plan::query()->find((int) $this->plan_id)?->isFamily() ?? false);
    }

    public function save(): void
    {
        $this->client ? $this->authorize('update', $this->client) : $this->authorize('create', Client::class);

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
            'gym_id' => ['nullable', Rule::exists('gyms', 'id')->where('user_id', auth()->id())],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'started_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'members' => ['array', 'max:'.Client::MAX_MEMBERS],
            'members.*.name' => ['nullable', 'string', 'max:100'],
        ]);

        unset($data['members']);

        $data['email'] = $data['email'] ?: null;
        $data['plan_id'] = $data['plan_id'] ?: null;
        $data['gym_id'] = $data['gym_id'] ?: null;
        $data['started_at'] = $data['started_at'] ?: null;
        $data['last_name'] = $data['last_name'] ?: null;

        $isNew = $this->client === null;

        try {
            DB::transaction(function () use ($data) {
                if ($this->client) {
                    $this->client->update($data);
                } else {
                    $this->client = Client::create($data);
                }

                $this->syncMembers();
            });
        } catch (BillingException $e) {
            $this->addError('members', $e->getMessage());

            return;
        }

        Flux::toast($isNew ? 'Client added.' : 'Client updated.', variant: 'success');

        $this->redirectRoute('clients.show', $this->client, navigate: true);
    }

    /**
     * Reconcile the typed names against the members on file: rename what is still there,
     * create what is new, and retire the rest. A member who has been on a session is
     * deactivated rather than deleted, so past sessions keep saying who trained.
     */
    private function syncMembers(): void
    {
        if (! $this->client->isOnFamilyPlan()) {
            return;
        }

        $existing = $this->client->members()->get()->keyBy('id');
        $kept = [];
        $order = 0;

        foreach ($this->members as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            if (count($kept) >= Client::MAX_MEMBERS) {
                throw new BillingException('A family can have at most '.Client::MAX_MEMBERS.' members.');
            }

            $member = ($row['id'] ?? null) && $existing->has($row['id'])
                ? tap($existing[$row['id']])->update(['name' => $name, 'sort_order' => $order, 'active' => true])
                : $this->client->members()->create(['user_id' => $this->client->user_id, 'name' => $name, 'sort_order' => $order, 'active' => true]);

            $kept[] = $member->id;
            $order++;
        }

        foreach ($existing as $member) {
            if (in_array($member->id, $kept, true)) {
                continue;
            }

            $member->hasHistory() ? $member->update(['active' => false]) : $member->delete();
        }
    }

    public function render()
    {
        return view('livewire.clients.form', [
            'plans' => Plan::query()->where('active', true)->orderBy('name')->get(),
            'gyms' => Gym::query()->active()->orderBy('name')->get(),
        ])->title($this->client ? 'Edit client' : 'Add client');
    }
}
