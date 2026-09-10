<?php

namespace App\Livewire\Sessions\Concerns;

use App\Models\Client;
use App\Models\Service;
use App\Services\SessionPricer;
use App\Support\AttendeeLine;
use Illuminate\Support\Collection;

/**
 * Shared "what will each attendee be charged?" preview used by the log and show screens.
 * The arithmetic itself lives in SessionPricer, which is also what CompleteTrainingSession
 * uses, so the preview cannot quote a price the charge then disagrees with.
 *
 * @property array<int, array{attended: bool, override: string, members?: array<int, array{attended: bool, override: string}>}> $attendees keyed by client id
 */
trait PreviewsCharges
{
    /**
     * @param  Collection<int, Client>  $clients  keyed by id, with plan.rates and members loaded
     * @return array{people: int, headcount: int, tier: string, rateTier: string, total: float, rows: array<int, array{client: Client, attended: bool, people: int, subtotal: ?float, gst: ?float, total: ?float, members: array<int, array{id: int, name: string, subtotal: float}>, error: ?string}>}
     */
    protected function previewCharges(?Service $service, Collection $clients): array
    {
        $lines = [];

        foreach ($this->attendees as $clientId => $state) {
            $client = $clients->get((int) $clientId);

            if ($client) {
                $lines[(int) $clientId] = AttendeeLine::fromState($client, $this->stateWithMemberNames($client, $state));
            }
        }

        $priced = app(SessionPricer::class)->price($lines, $service, auth()->user()->effectiveGstRate());

        $rows = [];
        foreach ($priced['rows'] as $clientId => $row) {
            $rows[$clientId] = [
                'client' => $row['line']->client,
                'attended' => $row['attended'],
                'people' => $row['people'],
                'subtotal' => $row['subtotal'],
                'gst' => $row['gst'],
                'total' => $row['total'],
                'members' => $row['members'],
                'error' => $row['error'],
            ];
        }

        return [
            'people' => $priced['people'],
            'headcount' => $priced['people'],
            'tier' => $priced['tier'],
            'rateTier' => $priced['rateTier'],
            'total' => $priced['total'],
            'rows' => $rows,
        ];
    }

    /**
     * The form only tracks which members are ticked; names come from the client so the
     * preview and the receipt can say who trained.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function stateWithMemberNames(Client $client, array $state): array
    {
        if (! $client->isOnFamilyPlan()) {
            return $state;
        }

        $names = $client->members->keyBy('id');

        foreach ($state['members'] ?? [] as $memberId => $member) {
            $state['members'][$memberId]['name'] = $names->get((int) $memberId)?->name ?? '';
        }

        return $state;
    }
}
