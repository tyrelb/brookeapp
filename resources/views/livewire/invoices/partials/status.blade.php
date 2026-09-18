{{-- An invoice's status badge, with when it was paid or last chased underneath. --}}
@php($status = $invoice->status())
<flux:badge size="sm" :color="$status->color()">{{ $status->label() }}</flux:badge>
@if ($paidOn = $invoice->paidOn())
    <div class="mt-0.5 text-xs text-zinc-500">{{ $paidOn->format('M j') }}</div>
@elseif ($invoice->reminded_at && $status->isOutstanding())
    <div class="mt-0.5 text-xs text-zinc-500" title="{{ $invoice->reminder_count }} {{ Str::plural('reminder', $invoice->reminder_count) }} sent">Reminded {{ $invoice->reminded_at->format('M j') }}</div>
@endif
