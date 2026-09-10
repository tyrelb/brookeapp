<?php

namespace App\Livewire\Settings;

use App\Enums\GymBillingModel;
use App\Models\Gym;
use App\Models\TrainingSession;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Gyms')]
class Gyms extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $billing_model = 'usage';

    public string $monthly_fee = '';

    /** @var array<int, string> people => price */
    public array $rates = [];

    /** Whether this gym pays the trainer to cover its own clients. */
    public bool $covers_clients = false;

    /** @var array<int, string> people => what the gym pays her, before HER GST */
    public array $coverRates = [];

    public bool $charges_gst = true;

    public string $gst_rate = '5.00';

    public bool $is_default = false;

    public bool $active = true;

    public string $notes = '';

    public bool $assignExisting = true;

    public function create(): void
    {
        $this->authorize('create', Gym::class);
        $this->resetForm();
        $this->is_default = Gym::query()->active()->doesntExist();
        Flux::modal('gym-form')->show();
    }

    public function edit(int $id): void
    {
        $gym = Gym::query()->findOrFail($id);
        $this->authorize('update', $gym);

        $this->resetForm();
        $this->editingId = $gym->id;
        $this->name = $gym->name;
        $this->billing_model = $gym->billing_model->value;
        $this->monthly_fee = $gym->monthly_fee !== null ? number_format((float) $gym->monthly_fee, 2, '.', '') : '';
        foreach (range(1, Gym::MAX_PEOPLE) as $n) {
            $price = $gym->usage_rates[$n] ?? $gym->usage_rates[(string) $n] ?? null;
            $this->rates[$n] = $price !== null && $price !== '' ? number_format((float) $price, 2, '.', '') : '';
        }
        $this->covers_clients = $gym->coversSessions();
        foreach (range(1, Gym::MAX_PEOPLE) as $n) {
            $price = $gym->cover_rates[$n] ?? $gym->cover_rates[(string) $n] ?? null;
            $this->coverRates[$n] = $price !== null && $price !== '' ? number_format((float) $price, 2, '.', '') : '';
        }
        $this->charges_gst = $gym->charges_gst;
        $this->gst_rate = number_format((float) $gym->gst_rate, 2, '.', '');
        $this->is_default = $gym->is_default;
        $this->active = $gym->active;
        $this->notes = (string) $gym->notes;
        Flux::modal('gym-form')->show();
    }

    public function save(): void
    {
        $model = GymBillingModel::tryFrom($this->billing_model);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('gyms', 'name')->where('user_id', auth()->id())->ignore($this->editingId)],
            'billing_model' => ['required', Rule::enum(GymBillingModel::class)],
            'monthly_fee' => [Rule::requiredIf($model?->includesMonthly() ?? false), 'nullable', 'numeric', 'min:0', 'max:100000'],
            'rates' => ['array'],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'rates.1' => [Rule::requiredIf($model?->includesUsage() ?? false), 'nullable', 'numeric'],
            'covers_clients' => ['boolean'],
            'coverRates' => ['array'],
            'coverRates.*' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'coverRates.1' => [Rule::requiredIf($this->covers_clients), 'nullable', 'numeric'],
            'charges_gst' => ['boolean'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'is_default' => ['boolean'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'rates.1.required' => 'Enter at least the hourly rate for one person.',
            'coverRates.1.required' => 'Enter at least what the gym pays you for one person.',
            'monthly_fee.required' => 'Enter the monthly rate for this billing model.',
        ]);

        $activeOthers = Gym::query()->active()->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->count();

        if ($data['active'] && $activeOthers >= Gym::MAX_ACTIVE) {
            $this->addError('active', 'You can have up to '.Gym::MAX_ACTIVE.' active gyms. Deactivate one first.');

            return;
        }

        $rates = [];
        foreach ($data['rates'] as $people => $price) {
            if ($price !== null && $price !== '' && (int) $people >= 1 && (int) $people <= Gym::MAX_PEOPLE) {
                $rates[(int) $people] = round((float) $price, 2);
            }
        }

        $coverRates = [];
        foreach ($data['coverRates'] as $people => $price) {
            if ($price !== null && $price !== '' && (int) $people >= 1 && (int) $people <= Gym::MAX_PEOPLE) {
                $coverRates[(int) $people] = round((float) $price, 2);
            }
        }

        $attributes = [
            'name' => $data['name'],
            'billing_model' => $model,
            'monthly_fee' => $model->includesMonthly() ? round((float) $data['monthly_fee'], 2) : null,
            'usage_rates' => $model->includesUsage() ? $rates : null,
            // Deliberately not gated on the billing model, unlike usage_rates above:
            // a gym she only pays rent to can still pay her to cover its clients.
            'cover_rates' => $this->covers_clients ? $coverRates : null,
            'charges_gst' => $data['charges_gst'],
            'gst_rate' => round((float) $data['gst_rate'], 2),
            'is_default' => $data['is_default'] && $data['active'],
            'active' => $data['active'],
            'notes' => $data['notes'] ?: null,
        ];

        DB::transaction(function () use ($attributes) {
            $isFirst = Gym::query()->doesntExist();

            if ($this->editingId) {
                $gym = Gym::query()->findOrFail($this->editingId);
                $this->authorize('update', $gym);
                $gym->update($attributes);
            } else {
                $this->authorize('create', Gym::class);
                $gym = Gym::create($attributes);

                if ($isFirst && $this->assignExisting) {
                    TrainingSession::query()->whereNull('gym_id')->update(['gym_id' => $gym->id]);
                }
            }

            if ($gym->is_default) {
                Gym::query()->whereKeyNot($gym->id)->update(['is_default' => false]);
            }
        });

        Flux::toast($this->editingId ? 'Gym updated.' : 'Gym added.', variant: 'success');
        $this->resetForm();
        Flux::modal('gym-form')->close();
    }

    public function delete(int $id): void
    {
        $gym = Gym::query()->withCount('trainingSessions')->findOrFail($id);
        $this->authorize('delete', $gym);

        if ($gym->training_sessions_count > 0 || $gym->usageReports()->exists()) {
            Flux::toast('This gym has sessions or reports attached. Mark it inactive instead.', variant: 'warning');

            return;
        }

        $gym->delete();
        Flux::toast('Gym removed.', variant: 'success');
    }

    public function makeDefault(int $id): void
    {
        $gym = Gym::query()->findOrFail($id);
        $this->authorize('update', $gym);

        Gym::query()->update(['is_default' => false]);
        $gym->update(['is_default' => true, 'active' => true]);
        Flux::toast("{$gym->name} is now the default gym for new sessions.", variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'billing_model', 'monthly_fee', 'charges_gst', 'gst_rate', 'is_default', 'active', 'notes', 'assignExisting', 'covers_clients');
        $this->rates = [];
        foreach (Gym::DEFAULT_RATES as $people => $price) {
            $this->rates[$people] = number_format($price, 2, '.', '');
        }
        // Blank above 2: an unset tier falls back to the nearest lower rate.
        $this->coverRates = array_fill_keys(range(1, Gym::MAX_PEOPLE), '');
        foreach (Gym::DEFAULT_COVER_RATES as $people => $price) {
            $this->coverRates[$people] = number_format($price, 2, '.', '');
        }
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.settings.gyms', [
            'gyms' => Gym::query()->withCount('trainingSessions')->orderByDesc('active')->orderByDesc('is_default')->orderBy('name')->get(),
            'models' => GymBillingModel::cases(),
            'isFirst' => Gym::query()->doesntExist(),
            'unassigned' => TrainingSession::query()->whereNull('gym_id')->count(),
            'people' => range(1, Gym::MAX_PEOPLE),
        ]);
    }
}
