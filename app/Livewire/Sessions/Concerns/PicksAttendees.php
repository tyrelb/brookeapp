<?php

namespace App\Livewire\Sessions\Concerns;

use App\Models\Client;
use App\Models\Gym;
use Illuminate\Database\Eloquent\Collection;

/**
 * The "add clients to this session" list shared by the log and bulk-log screens.
 *
 * @property array<int, array{attended: bool, override: string}> $attendees keyed by client id
 * @property string $clientSearch
 * @property string $gym_id
 * @property bool $gymChosen
 */
trait PicksAttendees
{
    public function addClient(int $clientId): void
    {
        $client = Client::query()->find($clientId);

        if (! $client || isset($this->attendees[$clientId])) {
            return;
        }

        $this->attendees[$clientId] = ['attended' => true, 'override' => ''];
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
        return Client::query()->active()->with('plan')
            ->whereNotIn('id', array_keys($this->attendees))
            ->search($this->clientSearch)
            ->orderBy('first_name')->orderBy('last_name')
            ->limit($this->clientSearch === '' ? 12 : 25)
            ->get();
    }
}
