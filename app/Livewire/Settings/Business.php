<?php

namespace App\Livewire\Settings;

use App\Enums\PaymentMethod;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Business settings')]
class Business extends Component
{
    public string $business_name = '';

    public string $phone = '';

    public bool $gst_registered = true;

    public string $gst_number = '';

    public string $gst_rate = '5.00';

    /** @var list<string> */
    public array $payment_methods = [];

    public string $etransfer_email = '';

    public string $booking_instructions = '';

    public bool $notify_on_booking = true;

    public bool $notify_on_completion = false;

    public function mount(): void
    {
        $user = auth()->user();

        $this->business_name = (string) $user->business_name;
        $this->phone = (string) $user->phone;
        $this->gst_registered = (bool) $user->gst_registered;
        $this->gst_number = (string) $user->gst_number;
        $this->gst_rate = number_format((float) $user->gst_rate, 2, '.', '');
        $this->payment_methods = collect($user->enabledPaymentMethods())->map->value->all();
        $this->etransfer_email = (string) $user->etransfer_email;
        $this->booking_instructions = (string) $user->booking_instructions;
        $this->notify_on_booking = (bool) $user->notify_on_booking;
        $this->notify_on_completion = (bool) $user->notify_on_completion;
    }

    public function save(): void
    {
        $data = $this->validate([
            'business_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'gst_registered' => ['boolean'],
            'gst_number' => ['nullable', 'string', 'max:30'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'payment_methods' => ['array', 'min:1'],
            'payment_methods.*' => [Rule::enum(PaymentMethod::class)],
            'etransfer_email' => ['nullable', 'email', 'max:255'],
            'booking_instructions' => ['nullable', 'string', 'max:2000'],
            'notify_on_booking' => ['boolean'],
            'notify_on_completion' => ['boolean'],
        ], [
            'payment_methods.min' => 'Choose at least one way to get paid.',
        ]);

        foreach (['business_name', 'phone', 'gst_number', 'etransfer_email', 'booking_instructions'] as $key) {
            $data[$key] = $data[$key] ?: null;
        }

        $data['payment_methods'] = array_values($data['payment_methods']);

        auth()->user()->update($data);

        Flux::toast('Business settings saved.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.settings.business', [
            'methodOptions' => PaymentMethod::options(),
        ]);
    }
}
