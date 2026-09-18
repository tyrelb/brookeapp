<?php

use App\Actions\BookSessionSeries;
use App\Enums\SessionStatus;
use App\Livewire\Sessions\Calendar;
use App\Livewire\Sessions\SessionSheet;
use App\Mail\SessionBookedMail;
use App\Mail\SessionCancelledMail;
use App\Mail\SessionCompletedMail;
use App\Models\Client;
use App\Models\FamilyMember;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(now()->parse('2026-09-15 12:00:00')); // a Tuesday

    $this->trainer = User::factory()->create(['notify_on_completion' => false]);
    $this->actingAs($this->trainer);

    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $this->plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 30]);

    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id, 'first_name' => 'Ava', 'last_name' => 'Nguyen', 'email' => 'ava@example.com']);
    $this->ben = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id, 'first_name' => 'Ben', 'last_name' => 'Okafor', 'email' => null]);
});

/** A session for this trainer with these clients booked on it. */
function listSession($test, string $startsAt, array $clients, array $attributes = []): TrainingSession
{
    $session = TrainingSession::factory()->create([
        'user_id' => $test->trainer->id,
        'service_id' => $test->service->id,
        'starts_at' => $startsAt,
        ...$attributes,
    ]);

    foreach ($clients as $client) {
        SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $client->id]);
    }

    return $session;
}

/** Marks a session as if its calendar invites had gone out. */
function invited(TrainingSession $session): TrainingSession
{
    $session->update(['invites_sent_at' => now(), 'ics_uid' => 'test-'.$session->id.'@brookeapp']);
    $session->attendees()->update(['invite_sent_at' => now()]);

    return $session->fresh();
}

function weeklySeries($test, string $from, string $to): Collection
{
    $series = app(BookSessionSeries::class)->handle([
        'user_id' => $test->trainer->id,
        'service_id' => $test->service->id,
        'gym_id' => null,
        'starts_on' => $from,
        'ends_on' => $to,
        'time' => '09:00',
        'duration_minutes' => 60,
        'interval_weeks' => 1,
        'weekdays' => [2],
        'notes' => null,
    ], [$test->ava->id => ['attended' => true, 'price_override' => null]], false);

    return $series->sessions()->orderBy('starts_at')->get();
}

it('lists two weeks of sessions under day headings, skipping empty days', function () {
    listSession($this, '2026-09-15 07:00:00', [$this->ava]);
    listSession($this, '2026-09-17 09:00:00', [$this->ava, $this->ben], ['status' => SessionStatus::Completed, 'completed_at' => now()]);
    listSession($this, '2026-09-28 18:00:00', [$this->ben]);
    listSession($this, '2026-10-02 08:00:00', [$this->ava]);
    listSession($this, '2026-09-16 10:00:00', [$this->ben], ['status' => SessionStatus::Cancelled]);

    $other = User::factory()->create();
    $zed = Client::factory()->create(['user_id' => $other->id, 'first_name' => 'Zed', 'last_name' => 'Other']);
    $theirs = TrainingSession::factory()->create(['user_id' => $other->id, 'starts_at' => '2026-09-18 08:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $theirs->id, 'client_id' => $zed->id]);

    Livewire::test(Calendar::class, ['date' => '2026-09-15', 'view' => 'list'])
        ->assertSee('Sep 15 – Sep 28, 2026')
        ->assertSeeInOrder([
            'Tuesday – Sep 15', 'Ava Nguyen', 'Not logged yet', '7:00 am', '8:00 am',
            'Wednesday – Sep 16', 'Ben Okafor', 'Cancelled',
            'Thursday – Sep 17', 'Ava Nguyen and Ben Okafor', 'Completed',
            'Monday – Sep 28', 'Ben Okafor', '6:00 pm',
        ])
        ->assertDontSee('Friday – Sep 18')
        ->assertDontSee('Zed')
        ->assertDontSee('Friday – Oct 2')
        ->call('showMore')
        ->assertSee('Sep 15 – Oct 12, 2026')
        ->assertSee('Friday – Oct 2')
        ->call('next')
        ->assertSee('Sep 29 – Oct 12, 2026');
});

it('always shows today in the list, even with nothing booked', function () {
    listSession($this, '2026-09-17 09:00:00', [$this->ava]);

    Livewire::test(Calendar::class, ['view' => 'list'])
        ->assertSeeInOrder(['Tuesday – Sep 15', 'Today', 'Nothing booked today.', 'Thursday – Sep 17', 'Ava Nguyen']);

    Livewire::test(Calendar::class, ['date' => '2026-10-01', 'view' => 'list'])
        ->assertSee('Nothing booked for Oct 1 – Oct 14, 2026.');
});

it('names the people a gym cover session was for', function () {
    TrainingSession::factory()->cover(['Guest One', 'Guest Two'])->create([
        'user_id' => $this->trainer->id,
        'service_id' => $this->service->id,
        'starts_at' => '2026-09-16 09:00:00',
    ]);

    Livewire::test(Calendar::class, ['view' => 'list'])->assertSee('Guest One and Guest Two');
    Livewire::test(Calendar::class, ['date' => '2026-09-16', 'view' => 'day'])->assertSee('Guest One and Guest Two');
});

it('opens the list on a phone unless a view is asked for', function () {
    $listHint = 'Tap a session to log, edit or cancel it.';

    $this->withUnencryptedCookie('narrow_screen', '1')->get(route('sessions.calendar'))->assertOk()->assertSee($listHint);
    $this->withUnencryptedCookie('narrow_screen', '1')->get(route('sessions.calendar', ['view' => 'day']))->assertOk()->assertDontSee($listHint);
    $this->withUnencryptedCookie('narrow_screen', '0')->get(route('sessions.calendar'))->assertOk()->assertDontSee($listHint);
    $this->get(route('sessions.calendar'))->assertOk()->assertDontSee($listHint);
});

it('shows a session in the sheet with what can be done to it', function () {
    $session = listSession($this, '2026-09-16 09:00:00', [$this->ava, $this->ben]);

    Livewire::test(SessionSheet::class)
        ->call('open', $session->id)
        ->assertSee('Ava Nguyen and Ben Okafor')
        ->assertSee('Wednesday, Sep 16 · 9:00 am – 10:00 am')
        ->assertSee('Personal Training')
        ->assertSee('Log session')
        ->assertSee('Open full session');

    $done = listSession($this, '2026-09-14 09:00:00', [$this->ava], ['status' => SessionStatus::Completed, 'completed_at' => now()]);

    Livewire::test(SessionSheet::class)
        ->call('open', $done->id)
        ->assertSee('Completed')
        ->assertDontSee('Log session');
});

it('logs a session from the sheet, charging only who came', function () {
    $session = listSession($this, '2026-09-15 09:00:00', [$this->ava, $this->ben]);

    Livewire::test(SessionSheet::class)
        ->call('open', $session->id)
        ->call('switchTo', 'log')
        ->assertSee('Who came?')
        ->assertSee('value="no_show"', false)
        ->set("attendees.{$this->ben->id}.attendance", 'no_show')
        ->set('sendReceipts', true)
        ->call('log')
        ->assertHasNoErrors()
        ->assertDispatched('session-updated');

    $session->refresh();
    expect($session->isCompleted())->toBeTrue()
        ->and($this->ava->balance())->toBeLessThan(0.0)
        ->and($this->ben->balance())->toBe(0.0);

    Mail::assertQueued(SessionCompletedMail::class, fn (SessionCompletedMail $m) => $m->hasTo('ava@example.com'));
});

it('asks for a reason before charging a late cancel from the sheet', function () {
    $session = listSession($this, '2026-09-15 09:00:00', [$this->ava]);

    $sheet = Livewire::test(SessionSheet::class)
        ->call('open', $session->id)
        ->call('switchTo', 'log')
        ->set("attendees.{$this->ava->id}.attendance", 'late_cancel')
        ->assertSee('Reason (required)')
        ->call('log')
        ->assertHasErrors('attendees')
        ->assertNotDispatched('session-updated');

    expect($session->fresh()->isScheduled())->toBeTrue();

    $sheet->set("attendees.{$this->ava->id}.client_note", 'Cancelled the morning of')
        ->call('log')
        ->assertHasNoErrors();

    expect($session->fresh()->isCompleted())->toBeTrue()
        ->and($this->ava->balance())->toBeLessThan(0.0);
});

it('logs a family from the sheet by ticking who came', function () {
    $family = Plan::factory()->family()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $family->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    $barnes = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $family->id, 'first_name' => 'Barnes', 'last_name' => 'Family']);
    $mom = FamilyMember::factory()->create(['user_id' => $this->trainer->id, 'client_id' => $barnes->id, 'name' => 'Mom']);
    FamilyMember::factory()->create(['user_id' => $this->trainer->id, 'client_id' => $barnes->id, 'name' => 'Dad']);

    $session = listSession($this, '2026-09-15 09:00:00', []);
    SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $barnes->id, 'attended' => false]);

    Livewire::test(SessionSheet::class)
        ->call('open', $session->id)
        ->call('switchTo', 'log')
        ->assertSee('Mom')
        ->assertSee('Dad')
        ->assertSee('No one ticked')
        ->assertDontSee('value="no_show"', false)
        ->set("attendees.{$barnes->id}.members.{$mom->id}.attended", true)
        ->assertDontSee('No one ticked')
        ->call('log')
        ->assertHasNoErrors();

    $attendee = $session->fresh()->attendees->first();
    expect($session->fresh()->isCompleted())->toBeTrue()
        ->and($attendee->peopleNames())->toBe(['Mom'])
        ->and($barnes->balance())->toBeLessThan(0.0);
});

it('moves a session from the sheet and emails an updated invite', function () {
    $session = invited(listSession($this, '2026-09-16 09:00:00', [$this->ava]));
    listSession($this, '2026-09-18 10:00:00', [$this->ben]);

    Livewire::test(SessionSheet::class)
        ->call('open', $session->id)
        ->call('switchTo', 'edit')
        ->set('newDate', '2026-09-18')
        ->set('newTime', '10:30')
        ->set('newDuration', '45')
        ->assertSee('Something else is booked at this time')
        ->assertSee('Save anyway')
        ->call('reschedule')
        ->assertHasNoErrors()
        ->assertDispatched('session-updated');

    $session->refresh();
    expect($session->starts_at->format('Y-m-d H:i'))->toBe('2026-09-18 10:30')
        ->and($session->duration_minutes)->toBe(45)
        ->and($session->service_id)->toBe($this->service->id);

    Mail::assertQueued(SessionBookedMail::class, fn (SessionBookedMail $m) => $m->isUpdate && $m->hasTo('ava@example.com'));
});

it('moves this and the following sessions of a repeat from the sheet', function () {
    [$first, $second, $third] = weeklySeries($this, '2026-09-15', '2026-09-29')->all();

    Livewire::test(SessionSheet::class)
        ->call('open', $second->id)
        ->call('switchTo', 'edit')
        ->assertSee('This and the 1 following session')
        ->set('newTime', '11:00')
        ->set('rescheduleScope', 'following')
        ->call('reschedule')
        ->assertHasNoErrors();

    expect($first->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-15 09:00')
        ->and($second->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-22 11:00')
        ->and($third->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-29 11:00');
});

it('cancels a session from the sheet and emails the cancellation', function () {
    $session = invited(listSession($this, '2026-09-16 09:00:00', [$this->ava, $this->ben]));

    Livewire::test(SessionSheet::class)
        ->call('open', $session->id)
        ->call('switchTo', 'cancel')
        ->assertSee('Invited clients will be emailed a cancellation.')
        ->assertDontSee('following')
        ->call('cancel')
        ->assertDispatched('session-updated');

    expect($session->fresh()->isCancelled())->toBeTrue();
    Mail::assertQueued(SessionCancelledMail::class, fn (SessionCancelledMail $m) => $m->hasTo('ava@example.com'));
});

it('cancels this and the following sessions of a repeat from the sheet', function () {
    [$first, $second, $third] = weeklySeries($this, '2026-09-15', '2026-09-29')->all();

    Livewire::test(SessionSheet::class)
        ->call('open', $second->id)
        ->call('switchTo', 'cancel')
        ->assertSee('Cancel this & 1 following')
        ->call('cancelFollowing');

    expect($first->fresh()->isScheduled())->toBeTrue()
        ->and($second->fresh()->isCancelled())->toBeTrue()
        ->and($third->fresh()->isCancelled())->toBeTrue();
});

it('will not open another trainer\'s session', function () {
    $other = User::factory()->create();
    $theirs = TrainingSession::factory()->create(['user_id' => $other->id, 'starts_at' => '2026-09-16 09:00:00']);

    expect(fn () => Livewire::test(SessionSheet::class)->call('open', $theirs->id))
        ->toThrow(ModelNotFoundException::class);
});
