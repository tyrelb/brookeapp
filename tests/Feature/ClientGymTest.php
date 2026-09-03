<?php

use App\Livewire\Clients\Form;
use App\Livewire\Sessions\Log;
use App\Livewire\Sessions\Show;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create();
    $this->actingAs($this->trainer);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id]);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    $this->west = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);
    $this->east = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Eastside']);
    $this->client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'gym_id' => $this->east->id]);
});

it('saves a default gym on the client form when there is more than one gym', function () {
    Livewire::test(Form::class)
        ->assertSet('gym_id', (string) $this->west->id)
        ->assertSee('Default gym')
        ->set('first_name', 'Sam')
        ->set('gym_id', (string) $this->east->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Client::where('first_name', 'Sam')->first()->gym_id)->toBe($this->east->id);

    $other = User::factory()->create();
    $theirs = Gym::factory()->create(['user_id' => $other->id]);
    Livewire::test(Form::class)->set('first_name', 'Bad')->set('gym_id', (string) $theirs->id)->call('save')->assertHasErrors(['gym_id']);
});

it('pre-fills the session gym from the first client added, unless the trainer chose one', function () {
    Livewire::test(Log::class)
        ->assertSet('gym_id', (string) $this->west->id)
        ->call('addClient', $this->client->id)
        ->assertSet('gym_id', (string) $this->east->id);

    Livewire::test(Log::class)
        ->set('gym_id', (string) $this->west->id)
        ->call('addClient', $this->client->id)
        ->assertSet('gym_id', (string) $this->west->id);
});

it('requires a gym on sessions once the trainer has one', function () {
    Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->set('gym_id', '')
        ->call('addClient', $this->client->id)
        ->set('gym_id', '')
        ->call('save', true)
        ->assertHasErrors(['gym_id']);

    expect(TrainingSession::count())->toBe(0);

    $session = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'gym_id' => $this->west->id]);

    Livewire::test(Show::class, ['trainingSession' => $session])
        ->set('newGymId', '')
        ->call('reschedule')
        ->assertHasErrors(['newGymId'])
        ->call('setGym', '');

    expect($session->fresh()->gym_id)->toBe($this->west->id);

    // Trainers with no gyms are not forced to pick one.
    Gym::query()->delete();
    Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->client->id)
        ->call('save', true)
        ->assertHasNoErrors();
});
