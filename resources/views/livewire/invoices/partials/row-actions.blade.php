{{-- Remind, Mark paid, Void and Delete for one invoice. The page must use ManagesInvoices. --}}
<div class="flex justify-end gap-1">
    {{-- Only while something is owed: voiding a paid invoice would tell the client it
         was cancelled when it was settled. --}}
    @if ($invoice->isOutstanding())
        <flux:button size="xs" variant="ghost" wire:click="remindInvoice({{ $invoice->id }})" wire:confirm="Email {{ $invoice->client->first_name }} a reminder about {{ $invoice->number }}?">Remind</flux:button>
        <flux:button size="xs" variant="ghost" wire:click="openMarkPaid({{ $invoice->id }})">Mark paid</flux:button>
        <flux:button size="xs" variant="ghost" wire:click="voidInvoice({{ $invoice->id }})" wire:confirm="Void {{ $invoice->number }}? {{ $invoice->client->first_name }} will no longer be asked for it. Anything already received stays on the ledger.">Void</flux:button>
    @endif
    @if ($invoice->canBeDeleted())
        <flux:button size="xs" variant="ghost" wire:click="deleteInvoice({{ $invoice->id }})" wire:confirm="Delete {{ $invoice->number }}? It disappears from every list and from {{ $invoice->client->first_name }}'s wallet page.">Delete</flux:button>
    @endif
</div>
