<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Business" subheading="Your business details, GST, and how clients can pay you">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:input wire:model="business_name" label="Business name" placeholder="Brooke Fitness" description="Shown to clients in emails and reports. Defaults to your name." />
            <flux:input wire:model="phone" label="Phone" type="tel" />

            <flux:separator />

            <flux:checkbox wire:model.live="gst_registered" label="I am registered for GST" description="When off, no GST is added to sessions or fees (small supplier)." />
            @if ($gst_registered)
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="gst_number" label="GST/HST number" placeholder="123456789 RT0001" />
                    <flux:input wire:model="gst_rate" label="GST rate (%)" type="number" step="0.01" min="0" max="30" description="5% in BC." />
                </div>
            @endif

            <flux:separator />

            <flux:checkbox.group wire:model="payment_methods" label="Accepted payment methods" description="Only these appear when recording a payment. Card payments can be added later.">
                @foreach ($methodOptions as $value => $label)
                    <flux:checkbox value="{{ $value }}" label="{{ $label }}" />
                @endforeach
            </flux:checkbox.group>
            <flux:input wire:model="etransfer_email" label="e-Transfer email" type="email" description="Where clients should send e-Transfers." />

            <flux:separator />

            <flux:textarea wire:model="booking_instructions" label="Booking instructions" rows="4" placeholder="To book or change a session, text me at… " description="Included in every client email so they know how to reach you to book or change a session." />

            <flux:separator />

            <flux:checkbox wire:model="notify_on_booking" label="Email a calendar invite when I book a session" description="Default for the Book session form. Clients get an .ics they can accept; changes and cancellations send updates." />
            <flux:checkbox wire:model="notify_on_completion" label="Email a receipt when I complete a session" description="Default for the Log session form. Shows what was deducted and the remaining Fitness Wallet balance." />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">Save</flux:button>
            </div>
        </form>
    </x-settings.layout>
</section>
