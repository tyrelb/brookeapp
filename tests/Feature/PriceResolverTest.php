<?php

use App\Exceptions\MissingRateException;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\User;
use App\Services\PriceResolver;

beforeEach(function () {
    $this->trainer = User::factory()->create();
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $this->wallet = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id, 'name' => 'Standard']);
    PlanRate::factory()->create(['plan_id' => $this->wallet->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $this->wallet->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 30]);
    $this->resolver = new PriceResolver;
});

it('uses the exact headcount tier', function () {
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->wallet->id]);

    expect($this->resolver->forAttendee($client, $this->service, 1))->toBe(60.00)
        ->and($this->resolver->forAttendee($client, $this->service, 2))->toBe(30.00);
});

it('falls back to the largest tier below the requested headcount', function () {
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->wallet->id]);

    expect($this->resolver->forAttendee($client, $this->service, 3))->toBe(30.00)
        ->and($this->resolver->forAttendee($client, $this->service, 4))->toBe(30.00);
});

it('throws when no rate exists for the service', function () {
    $other = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Nutrition']);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->wallet->id]);

    $this->resolver->forAttendee($client, $other, 1);
})->throws(MissingRateException::class, 'No Single rate is set for Nutrition');

it('throws when the client has no plan', function () {
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => null]);

    $this->resolver->forAttendee($client, $this->service, 1);
})->throws(MissingRateException::class);

it('charges nothing for monthly clients', function () {
    $monthly = Plan::factory()->monthly()->create(['user_id' => $this->trainer->id]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $monthly->id]);

    expect($this->resolver->forAttendee($client, $this->service, 1))->toBe(0.0);
});

it('lets a price override win over everything', function () {
    $monthly = Plan::factory()->monthly()->create(['user_id' => $this->trainer->id]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $monthly->id]);
    $noPlan = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => null]);

    expect($this->resolver->forAttendee($client, $this->service, 1, 25.00))->toBe(25.00)
        ->and($this->resolver->forAttendee($noPlan, $this->service, 1, 40.00))->toBe(40.00);
});
