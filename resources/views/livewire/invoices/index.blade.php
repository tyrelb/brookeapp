<div>
    <x-page-header title="Invoices" subtitle="Payment requests you've sent. Request a new one from a client's page." />

    <div class="mb-6 grid gap-4 md:grid-cols-3">
        <x-stat-card label="Outstanding" :value="money($summary['outstanding'])" :hint="$summary['open'].' open '.Str::plural('invoice', $summary['open'])" />
        <x-stat-card label="Overdue" :value="money($summary['overdue'])" :hint="$summary['overdueCount'].' past '.($summary['overdueCount'] === 1 ? 'its' : 'their').' due date'" />
        <x-stat-card label="Received this month" :value="money($summary['receivedThisMonth'])" hint="Payments recorded against an invoice" />
    </div>

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <div class="md:col-span-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search by number or client" clearable />
        </div>
        <flux:select wire:model.live="status">
            @foreach ($statuses as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($invoices->isEmpty())
        @if ($search !== '' || $status !== 'open')
            <x-empty-state title="No invoices match" description="Try another search or status." />
        @else
            <x-empty-state title="Nothing outstanding" description="Every invoice is paid or voided. To ask a client for money, open their page and choose Request payment.">
                <flux:button :href="route('clients.index')" wire:navigate>Go to clients</flux:button>
            </x-empty-state>
        @endif
    @else
        <flux:table :paginate="$invoices">
            <flux:table.columns>
                <flux:table.column>Number</flux:table.column>
                <flux:table.column>Client</flux:table.column>
                <flux:table.column>Issued</flux:table.column>
                <flux:table.column>Due</flux:table.column>
                <flux:table.column>For</flux:table.column>
                <flux:table.column align="end">Total</flux:table.column>
                <flux:table.column align="end">Received</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($invoices as $invoice)
                    @php($overdue = $invoice->due_on?->isBefore(today()) && $invoice->isOutstanding())
                    <flux:table.row :key="'invoice-'.$invoice->id" @class(['opacity-60' => $invoice->isVoided()])>
                        <flux:table.cell variant="strong" @class(['line-through' => $invoice->isVoided()])>{{ $invoice->number }}</flux:table.cell>
                        <flux:table.cell><flux:link :href="route('clients.show', $invoice->client)" wire:navigate>{{ $invoice->client->full_name }}</flux:link></flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $invoice->issued_on->format('M j, Y') }}</flux:table.cell>
                        <flux:table.cell @class(['whitespace-nowrap', 'font-medium text-red-600 dark:text-red-400' => $overdue])>{{ $invoice->due_on?->format('M j, Y') ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="max-w-56 truncate">{{ collect($invoice->lines)->pluck('description')->join(', ') }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ money($invoice->total) }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $invoice->paidAmount() > 0 ? money($invoice->paidAmount()) : '—' }}</flux:table.cell>
                        <flux:table.cell>@include('livewire.invoices.partials.status')</flux:table.cell>
                        <flux:table.cell>@include('livewire.invoices.partials.row-actions')</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @include('livewire.invoices.partials.mark-paid-modal')
</div>
