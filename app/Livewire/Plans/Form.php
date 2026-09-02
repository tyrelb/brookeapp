<?php

namespace App\Livewire\Plans;

use App\Enums\PlanType;
use App\Models\Plan;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?Plan $plan = null;

    public string $name = '';

    public string $type = PlanType::Wallet->value;

    public string $monthly_fee = '';

    public string $billing_day = '1';

    public string $description = '';

    public bool $active = true;

    /** @var array<int, array<int, string>> service_id => [headcount => price] */
    public array $rates = [];

    public function mount(?Plan $plan = null): void
    {
        $services = $this->services();

        foreach ($services as $service) {
            for ($h = 1; $h <= Plan::MAX_HEADCOUNT; $h++) {
                $this->rates[$service->id][$h] = '';
            }
        }

        if ($plan?->exists) {
            $this->authorize('update', $plan);
            $this->plan = $plan;
            $this->name = $plan->name;
            $this->type = $plan->type->value;
            $this->monthly_fee = $plan->monthly_fee !== null ? number_format((float) $plan->monthly_fee, 2, '.', '') : '';
            $this->billing_day = (string) ($plan->billing_day ?? 1);
            $this->description = (string) $plan->description;
            $this->active = $plan->active;

            foreach ($plan->rates as $rate) {
                $this->rates[$rate->service_id][$rate->headcount] = number_format((float) $rate->unit_price, 2, '.', '');
            }
        } else {
            $this->authorize('create', Plan::class);
            $this->plan = null;
        }
    }

    public function save(): void
    {
        $isMonthly = $this->type === PlanType::Monthly->value;

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(PlanType::class)],
            'monthly_fee' => [Rule::requiredIf($isMonthly), 'nullable', 'numeric', 'min:0', 'max:100000'],
            'billing_day' => [Rule::requiredIf($isMonthly), 'nullable', 'integer', 'min:1', 'max:28'],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
            'rates' => ['array'],
            'rates.*' => ['array'],
            'rates.*.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'rates.*.*.numeric' => 'Rates must be numbers.',
        ]);

        if (! $isMonthly) {
            $data['monthly_fee'] = null;
            $data['billing_day'] = null;
        }

        $data['description'] = $data['description'] ?: null;
        $rates = $data['rates'];
        unset($data['rates']);

        DB::transaction(function () use ($data, $rates, $isMonthly) {
            if ($this->plan) {
                $this->plan->update($data);
            } else {
                $this->plan = Plan::create($data);
            }

            $serviceIds = $this->services()->pluck('id')->all();

            foreach ($rates as $serviceId => $tiers) {
                if (! in_array((int) $serviceId, $serviceIds, true)) {
                    continue;
                }

                foreach ($tiers as $headcount => $price) {
                    $headcount = (int) $headcount;

                    if ($isMonthly || $price === null || $price === '') {
                        $this->plan->rates()->where('service_id', $serviceId)->where('headcount', $headcount)->delete();

                        continue;
                    }

                    $this->plan->rates()->updateOrCreate(
                        ['service_id' => $serviceId, 'headcount' => $headcount],
                        ['unit_price' => round((float) $price, 2)],
                    );
                }
            }
        });

        Flux::toast('Plan saved.', variant: 'success');
        $this->redirectRoute('plans.index', navigate: true);
    }

    private function services()
    {
        return Service::query()->where('active', true)->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.plans.form', [
            'services' => $this->services(),
            'headcounts' => range(1, Plan::MAX_HEADCOUNT),
        ])->title($this->plan ? 'Edit plan' : 'Add plan');
    }
}
