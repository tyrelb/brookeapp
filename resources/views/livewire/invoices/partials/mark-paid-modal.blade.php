{{-- Records money received against an invoice. The page must use ManagesInvoices and pass $markPaidInvoice and $paymentMethods. --}}
<flux:modal name="mark-paid" class="md:w-[28rem]">
    <form wire:submit="markPaid" class="space-y-5">
        <div>
            <flux:heading size="lg">{{ $markPaidInvoice ? 'Mark '.$markPaidInvoice->number.' paid' : 'Mark paid' }}</flux:heading>
            @if ($markPaidInvoice)
                <flux:subheading>{{ $markPaidInvoice->client->full_name }} · {{ money($markPaidInvoice->outstandingAmount()) }} outstanding of {{ money($markPaidInvoice->total) }}. This records the payment on their ledger.</flux:subheading>
            @endif
        </div>

        <flux:input wire:model="markPaidAmount" label="Amount received" type="number" step="0.01" min="0.01" placeholder="0.00" description="Less than the full amount leaves it partly paid." />
        <flux:select wire:model="markPaidMethod" label="Payment method">
            @foreach ($paymentMethods as $method)
                <flux:select.option value="{{ $method->value }}">{{ $method->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="markPaidDate" label="Date received" type="date" />
        <flux:input wire:model="markPaidReference" label="Reference" placeholder="Cheque # or e-Transfer reference" />

        @if ($markPaidInvoice?->client->email)
            <flux:checkbox wire:model="markPaidNotify" :label="'Email '.$markPaidInvoice->client->first_name.' a thank-you'" />
        @elseif ($markPaidInvoice)
            <flux:text class="text-sm">{{ $markPaidInvoice->client->first_name }} has no email address, so no thank-you will be sent.</flux:text>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">Record payment</flux:button>
        </div>
    </form>
</flux:modal>
