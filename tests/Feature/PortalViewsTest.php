<?php

use App\Actions\BookSessionSeries;
use App\Models\Client;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;

beforeEach(function () {
    $this->trainer = User::factory()->create();
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Ava', 'last_name' => 'N']);
    $this->zed = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Zed', 'last_name' => 'Other']);

    $this->travelTo(now()->parse('2026-09-01 08:00:00'));

    // 11 upcoming Tuesdays for Ava from Sep 8.
    app(BookSessionSeries::class)->handle([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'gym_id' => null,
        'starts_on' => '2026-09-08', 'ends_on' => '2026-11-17', 'time' => '09:00', 'duration_minutes' => 60,
        'interval_weeks' => 1, 'weekdays' => [2], 'notes' => null,
    ], [$this->ava->id => []]);

    // Zed has a session in September that must never show on Ava's page.
    $z = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-09 09:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $z->id, 'client_id' => $this->zed->id]);
});

it('pages upcoming sessions ten at a time in list view', function () {
    $url = $this->ava->portalUrl();

    $this->get($url)
        ->assertOk()
        ->assertSee('11 upcoming sessions through Nov 17, 2026')
        ->assertSee('Tuesday, September 8')
        ->assertSee('Tuesday, November 10')
        ->assertDontSee('Tuesday, November 17')
        ->assertSee('More upcoming')
        ->assertSee('every week on tue until nov 17, 2026');

    $this->get($url.'?view=list&upcoming=2')
        ->assertOk()
        ->assertSee('Tuesday, November 17')
        ->assertDontSee('Tuesday, September 8');
});

it('shows a month calendar with only this client\'s sessions and month navigation', function () {
    $url = $this->ava->portalUrl();

    $this->get($url.'?view=calendar&month=2026-09')
        ->assertOk()
        ->assertSee('September 2026')
        ->assertSee('4 sessions')            // Sep 8, 15, 22, 29
        ->assertSee('view=calendar&month=2026-10', false)
        ->assertSee('view=calendar&month=2026-08', false)
        ->assertDontSee('Zed');

    $this->get($url.'?view=calendar&month=2026-11')
        ->assertOk()
        ->assertSee('November 2026')
        ->assertSee('3 sessions');           // Nov 3, 10, 17

    $this->get($url.'?view=calendar&month=nonsense')->assertOk()->assertSee('September 2026');
});
