<?php

namespace App\Livewire\Sessions\Concerns;

use App\Models\Client;
use App\Models\Gym;
use Illuminate\Database\Eloquent\Collection;

/**
 * The "add clients to this session" list shared by the log and bulk-log screens.
 *
 * A family client is added as one row with its members listed underneath, none of them
 * ticked. The default has to fail closed: pre-ticking everyone would charge for people
 * who never showed up, while forgetting to tick anyone is caught before the charge.
 *
 * @property array<int, array{attended: bool, override: string, members: array<int, array{attended: bool, override: string}>}> $attendees keyed by client id
 * @property string $clientSearch
 * @property string $gym_id
 * @property bool $gymChosen
 */
trait PicksAttendees
{
    use SelectsMembers;

    public function addClient(int $clientId): void
    {
        $client = Client::query()->with('activeMembers')->find($clientId);

        if (! $client || isset($this->attendees[$clientId])) {
            return;
        }

        $this->attendees[$clientId] = [
            'attended' => ! $client->isOnFamilyPlan(),
            'override' => '',
            'members' => $this->blankMemberState($client),
        ];
        $this->clientSearch = '';

        // The first client's usual gym wins unless the trainer already chose one.
        if (! $this->gymChosen && $client->gym_id && Gym::query()->active()->whereKey($client->gym_id)->exists()) {
            $this->gym_id = (string) $client->gym_id;
        }
    }

    public function removeClient(int $clientId): void
    {
        unset($this->attendees[$clientId]);
    }

    public function updatedGymId(): void
    {
        $this->gymChosen = true;
    }

    /** @return Collection<int, Client> */
    protected function candidateClients()
    {
        return Client::query()->active()->with('plan', 'activeMembers')
            ->whereNotIn('id', array_keys($this->attendees))
            ->search($this->clientSearch)
            ->orderBy('first_name')->orderBy('last_name')
            ->limit($this->clientSearch === '' ? 12 : 25)
            ->get();
    }
}
