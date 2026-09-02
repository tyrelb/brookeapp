<?php

use App\Models\Client;
use App\Models\Plan;
use App\Models\Scopes\TrainerScope;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;

beforeEach(function () {
    $this->trainerA = User::factory()->create();
    $this->trainerB = User::factory()->create();
});

it('hides other trainers\' clients, plans, services, sessions and transactions', function () {
    $clientB = Client::factory()->create(['user_id' => $this->trainerB->id]);
    $planB = Plan::factory()->create(['user_id' => $this->trainerB->id]);
    $serviceB = Service::factory()->create(['user_id' => $this->trainerB->id]);
    $sessionB = TrainingSession::factory()->create(['user_id' => $this->trainerB->id, 'service_id' => $serviceB->id]);
    $txB = WalletTransaction::factory()->create(['user_id' => $this->trainerB->id, 'client_id' => $clientB->id]);

    $this->actingAs($this->trainerA);

    expect(Client::count())->toBe(0)
        ->and(Plan::count())->toBe(0)
        ->and(Service::count())->toBe(0)
        ->and(TrainingSession::count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0)
        ->and(Client::find($clientB->id))->toBeNull()
        ->and(Plan::find($planB->id))->toBeNull()
        ->and(Service::find($serviceB->id))->toBeNull()
        ->and(TrainingSession::find($sessionB->id))->toBeNull()
        ->and(WalletTransaction::find($txB->id))->toBeNull();

    // Unscoped access still works for console-style code.
    expect(Client::withoutGlobalScope(TrainerScope::class)->count())->toBe(1)
        ->and(Client::query()->forTrainer($this->trainerB)->count())->toBe(1);
});

it('assigns the authenticated trainer as owner on create', function () {
    $this->actingAs($this->trainerA);

    $client = Client::create(['first_name' => 'Sam', 'last_name' => 'Lee']);
    $service = Service::create(['name' => 'PT']);

    expect($client->user_id)->toBe($this->trainerA->id)
        ->and($service->user_id)->toBe($this->trainerA->id);
});

it('policies deny access to another trainer\'s records', function () {
    $clientB = Client::factory()->create(['user_id' => $this->trainerB->id]);
    $clientA = Client::factory()->create(['user_id' => $this->trainerA->id]);

    expect($this->trainerA->can('view', $clientB))->toBeFalse()
        ->and($this->trainerA->can('update', $clientB))->toBeFalse()
        ->and($this->trainerA->can('delete', $clientB))->toBeFalse()
        ->and($this->trainerA->can('view', $clientA))->toBeTrue()
        ->and($this->trainerA->can('update', $clientA))->toBeTrue();
});
