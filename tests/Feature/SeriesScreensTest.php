<?php

use App\Actions\BookSessionSeries;
use App\Livewire\Sessions\Index;
use App\Livewire\Sessions\Log;
use App\Livewire\Sessions\Show;
use App\Mail\SeriesMail;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionSeries;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->trainer = User::factory()->create(['notify_on_booking' => true]);
    $this->actingAs($this->trainer);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'PT']);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'first_name' => 'Ava', 'email' => 'ava@example.com']);
});

it('books a repeat from the Book form with a preview and a required end date', function () {
    $page = Livewire::test(Log::class, ['mode' => 'book'])
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-09-08')
        ->set('time', '09:00')
        ->call('addClient', $this->ava->id)
        ->set('repeat', true)
        ->assertSet('weekdays', [2])
        ->set('weekdays', [2, 4])
        ->set('until', '2026-09-24')
        ->assertSee('Creates 6 sessions')
        ->assertSee('Book 6 sessions');

    $page->set('until', '')->call('save', false)->assertHasErrors(['until']);
    $page->set('until', '2026-09-01')->call('save', false)->assertHasErrors(['until']);
    $page->set('until', '2028-01-01')->assertSee('12 months');

    $page->set('until', '2026-09-24')->call('save', false)->assertHasNoErrors()->assertRedirect(route('sessions.calendar', ['date' => '2026-09-08']));

    $series = SessionSeries::first();
    expect($series->sessions)->toHaveCount(6)
        ->and(TrainingSession::count())->toBe(6)
        ->and($series->sessions->every(fn ($s) => $s->attendees->count() === 1))->toBeTrue();

    Mail::assertQueued(SeriesMail::class, fn (SeriesMail $m) => $m->hasTo('ava@example.com') && $m->sessions->count() === 6);
});

it('offers this-and-following on series sessions only and applies changes prospectively', function () {
    $series = app(BookSessionSeries::class)->handle([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'gym_id' => null,
        'starts_on' => '2026-09-08', 'ends_on' => '2026-09-29', 'time' => '09:00', 'duration_minutes' => 60,
        'interval_weeks' => 1, 'weekdays' => [2], 'notes' => null,
    ], [$this->ava->id => []]); // Sep 8, 15, 22, 29
    $sessions = $series->sessions;

    $solo = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-09 09:00:00']);
    Livewire::test(Show::class, ['trainingSession' => $solo])->assertDontSee('This and the')->assertDontSee('Cancel this & following');

    Livewire::test(Show::class, ['trainingSession' => $sessions[1]])
        ->assertSee('2 more')
        ->assertSee('This and the 2 following sessions')
        ->set('rescheduleScope', 'following')
        ->set('newTime', '10:00')
        ->call('reschedule')
        ->assertHasNoErrors();

    expect($sessions[0]->fresh()->starts_at->format('H:i'))->toBe('09:00')
        ->and($sessions[1]->fresh()->starts_at->format('H:i'))->toBe('10:00')
        ->and($sessions[3]->fresh()->starts_at->format('H:i'))->toBe('10:00');

    Livewire::test(Show::class, ['trainingSession' => $sessions[2]->fresh()])->call('cancelFollowing');
    expect($sessions[1]->fresh()->isScheduled())->toBeTrue()
        ->and($sessions[2]->fresh()->isCancelled())->toBeTrue()
        ->and($sessions[3]->fresh()->isCancelled())->toBeTrue();

    // Apply attendees forward from the first session (which still has followers: session 2 only now).
    $ben = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Ben']);
    Livewire::test(Show::class, ['trainingSession' => $sessions[0]->fresh()])
        ->call('addClient', $ben->id)
        ->call('applyAttendeesToFollowing');
    expect($sessions[1]->fresh()->attendees->pluck('client_id')->sort()->values()->all())->toBe(collect([$this->ava->id, $ben->id])->sort()->values()->all());

    // The sessions index can be filtered to the series.
    Livewire::test(Index::class, ['series' => (string) $series->id])
        ->assertSee('Showing one repeat')
        ->assertSee('Every week on Tue');
});
