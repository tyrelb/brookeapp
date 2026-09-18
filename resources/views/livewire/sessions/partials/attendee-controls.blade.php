{{-- The row under a client in the attendee table: how they took part, and the note they read
     on their receipt, wallet page and training history. The note is required for a late cancel
     (the charge has to explain itself) and optional for everyone else. A family's own no-show is
     "nobody ticked", so its choice is only between came and cancelled late. --}}
@php($who = $client->isOnFamilyPlan() ? 'the family' : $client->first_name)
@php($isLate = \App\Enums\Attendance::fromInput($state['attendance'] ?? null) === \App\Enums\Attendance::LateCancel)
<tr wire:key="att-{{ $clientId }}-controls">
    <td colspan="{{ $colspan }}" class="px-3 pb-2.5 pt-0">
        <div class="flex flex-wrap items-start gap-2">
            <flux:select wire:model.live="attendees.{{ $clientId }}.attendance" size="sm" class="w-48 shrink-0" aria-label="Attendance">
                @foreach (\App\Enums\Attendance::cases() as $option)
                    @if (! $client->isOnFamilyPlan() || $option !== \App\Enums\Attendance::NoShow)
                        <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <div class="min-w-48 flex-1">
                <flux:input wire:model="attendees.{{ $clientId }}.client_note" size="sm" maxlength="150"
                    :placeholder="$isLate ? 'Reason (required) — '.$who.' sees this' : 'Note for '.$who.' (optional)'"
                    :aria-label="$isLate ? 'Reason for the late cancel' : 'Note for the client'" />
                <flux:error name="attendees.{{ $clientId }}.client_note" />
            </div>
        </div>
    </td>
</tr>
