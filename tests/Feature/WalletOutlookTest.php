<?php

use App\Livewire\Dashboard;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\WalletOutlook;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'gst_registered' => true]);
    $this->actingAs($this->trainer);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id]);
});

it('prices one session for a single client, gst included', function () {
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    $plan->rates()->create(['service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 87.50]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    expect(WalletOutlook::sessionCost($client))->toBe(91.88)
        ->and(WalletOutlook::sessionsRemaining($client, 2296.75))->toBe(24)
        ->and(WalletOutlook::isRunningLow($client, 2296.75))->toBeFalse()
        ->and(WalletOutlook::isRunningLow($client, 50.00))->toBeTrue();
});

it('prices a family session as one fare per member', function () {
    $plan = Plan::factory()->family()->create(['user_id' => $this->trainer->id]);
    $plan->rates()->create(['service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    $plan->rates()->create(['service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 45]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    foreach (['Sarah', 'Tom'] as $order => $name) {
        $client->members()->create(['user_id' => $this->trainer->id, 'name' => $name, 'sort_order' => $order]);
    }

    // Two members at the partner rate: 2 x $45 = $90, plus 5% GST.
    expect(WalletOutlook::sessionCost($client->fresh()))->toBe(94.50);
});

it('says nothing rather than dividing by zero when a plan has no rates', function () {
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    expect(WalletOutlook::sessionCost($client))->toBeNull()
        ->and(WalletOutlook::sessionsRemaining($client, 500))->toBeNull()
        ->and(WalletOutlook::isRunningLow($client, 0))->toBeFalse();
});

it('has nothing to say about a monthly membership, where sessions are included', function () {
    $plan = Plan::factory()->monthly(300)->create(['user_id' => $this->trainer->id]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    expect(WalletOutlook::sessionCost($client))->toBeNull()
        ->and(WalletOutlook::isRunningLow($client, -900))->toBeFalse();
});

it('still lists a client whose wallet will not cover one more session', function () {
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    $plan->rates()->create(['service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'first_name' => 'Skint']);

    Livewire\Livewire::test(Dashboard::class)->assertSee('Skint');
});
