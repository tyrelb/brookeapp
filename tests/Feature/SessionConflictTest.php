<?php

use App\Enums\SessionStatus;
use App\Livewire\Sessions\Calendar;
use App\Livewire\Sessions\Log;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\SessionConflicts;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5]);
    $this->actingAs($this->trainer);

    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    $this->client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'first_name' => 'Ava', 'last_name' => 'Nguyen']);

    $this->existing = TrainingSession::factory()->create([
        'user_id' => $this->trainer->id,
        'service_id' => $this->service->id,
        'starts_at' => '2026-09-09 09:00:00',
        'duration_minutes' => 60,
        'status' => SessionStatus::Scheduled,
    ]);
});

it('finds sessions that overlap a slot and ignores ones that only touch it', function () {
    expect(SessionConflicts::at('2026-09-09 09:30', 60))->toHaveCount(1)
        ->and(SessionConflicts::at('2026-09-09 08:30', 60))->toHaveCount(1)
        ->and(SessionConflicts::at('2026-09-09 09:00', 60))->toHaveCount(1)
        ->and(SessionConflicts::at('2026-09-09 10:00', 60))->toBeEmpty()  // starts as the other ends
        ->and(SessionConflicts::at('2026-09-09 08:00', 60))->toBeEmpty()  // ends as the other starts
        ->and(SessionConflicts::at('2026-09-10 09:00', 60))->toBeEmpty();
});

it('does not count cancelled sessions or the session being edited', function () {
    expect(SessionConflicts::at('2026-09-09 09:00', 60, ignoreId: $this->existing->id))->toBeEmpty();

    $this->existing->update(['status' => SessionStatus::Cancelled]);

    expect(SessionConflicts::at('2026-09-09 09:00', 60))->toBeEmpty();
});

it('catches a long session that runs into the next slot', function () {
    $this->existing->update(['starts_at' => '2026-09-09 06:00:00', 'duration_minutes' => 240]);

    expect(SessionConflicts::at('2026-09-09 09:00', 60))->toHaveCount(1);
});

it('reports which repeat dates already have something booked', function () {
    $clashes = SessionConflicts::forSlots(['2026-09-09 09:00', '2026-09-16 09:00', '2026-09-23 09:00'], 60);

    expect($clashes)->toHaveCount(1)
        ->and($clashes)->toHaveKey('2026-09-09 09:00');
});

it('warns on the booking screen but still books the session', function () {
    Livewire::test(Log::class, ['mode' => 'book'])
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-09-09')
        ->set('time', '09:30')
        ->set('duration_minutes', '60')
        ->call('addClient', $this->client->id)
        ->assertSee('Something else is booked at this time')
        ->assertSee('Book session anyway')
        ->call('save', false)
        ->assertHasNoErrors();

    expect(TrainingSession::query()->whereDate('starts_at', '2026-09-09')->count())->toBe(2);
});

it('keeps quiet when the slot is free', function () {
    Livewire::test(Log::class, ['mode' => 'book'])
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-09-10')
        ->set('time', '09:00')
        ->assertDontSee('Something else is booked at this time')
        ->assertSee('Book session');
});

it('counts the repeat dates that clash', function () {
    TrainingSession::factory()->create([
        'user_id' => $this->trainer->id,
        'service_id' => $this->service->id,
        'starts_at' => '2026-09-16 09:00:00',
        'duration_minutes' => 60,
    ]);

    Livewire::test(Log::class, ['mode' => 'book'])
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-09-09')
        ->set('time', '09:00')
        ->set('duration_minutes', '60')
        ->set('repeat', true)
        ->set('weekdays', [3])
        ->set('until', '2026-09-23')
        ->assertSee('2 of these dates already have a session at this time');
});

it('marks overlapping sessions in the day, week and month calendar', function () {
    TrainingSession::factory()->create([
        'user_id' => $this->trainer->id,
        'service_id' => $this->service->id,
        'starts_at' => '2026-09-09 09:30:00',
        'duration_minutes' => 60,
    ]);

    foreach (['day', 'week', 'month'] as $view) {
        Livewire::withQueryParams(['date' => '2026-09-09', 'view' => $view])
            ->test(Calendar::class)
            ->assertSee('2 sessions in this view overlap another booking');
    }
});

it('leaves a clean calendar unmarked', function () {
    Livewire::withQueryParams(['date' => '2026-09-09', 'view' => 'month'])
        ->test(Calendar::class)
        ->assertDontSee('overlap another booking');
});
