<?php

namespace App\Livewire\Sessions\Concerns;

use App\Enums\Attendance;
use App\Models\Client;

/**
 * Turning "which family members are ticked" in a form into something the models can
 * store, shared by every screen that picks attendees.
 *
 * @property array<int, array{attendance: string, override: string, client_note: string, members: array<int, array{attended: bool, override: string}>}> $attendees keyed by client id
 */
trait SelectsMembers
{
    /** Tick or clear every member of one family in a click. */
    public function toggleAllMembers(int $clientId, bool $attending = true): void
    {
        foreach ($this->attendees[$clientId]['members'] ?? [] as $memberId => $member) {
            $this->attendees[$clientId]['members'][$memberId]['attended'] = $attending;
        }
    }

    /** @return array<int, array{attended: bool, override: string}> */
    protected function blankMemberState(Client $client): array
    {
        if (! $client->isOnFamilyPlan()) {
            return [];
        }

        return $client->activeMembers
            ->mapWithKeys(fn ($member) => [$member->id => ['attended' => false, 'override' => '']])
            ->all();
    }

    /**
     * Whether this client is on the bill — the `attended` column. Member ticks decide it
     * for a family; for everyone else, anything but a no-show is charged, late cancels
     * included.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stateAttends(?Client $client, array $state): bool
    {
        if (! $client?->isOnFamilyPlan()) {
            return Attendance::fromInput($state['attendance'] ?? null) !== Attendance::NoShow;
        }

        return collect($state['members'] ?? [])->contains(fn ($member) => (bool) ($member['attended'] ?? false));
    }

    /**
     * Whether this client is charged but was not in the room — the `late_cancelled` column.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stateLateCancelled(array $state): bool
    {
        return Attendance::fromInput($state['attendance'] ?? null) === Attendance::LateCancel;
    }

    /**
     * The client-visible reason, or null when the trainer left it blank.
     *
     * @param  array<string, mixed>  $state
     */
    protected function stateClientNote(array $state): ?string
    {
        $note = trim((string) ($state['client_note'] ?? ''));

        return $note === '' ? null : $note;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, array{attended: bool, price_override: ?float}>
     */
    protected function memberSelection(array $state): array
    {
        $selection = [];

        foreach ($state['members'] ?? [] as $memberId => $member) {
            $selection[(int) $memberId] = [
                'attended' => (bool) ($member['attended'] ?? false),
                'price_override' => ($member['override'] ?? '') !== '' ? round((float) $member['override'], 2) : null,
            ];
        }

        return $selection;
    }
}
