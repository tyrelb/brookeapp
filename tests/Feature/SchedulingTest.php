<?php

use App\Actions\RecordPayment;
use App\Enums\PaymentMethod;
use App\Livewire\Sessions\Calendar;
use App\Livewire\Sessions\Log;
use App\Livewire\Sessions\Show;
use App\Livewire\Settings\Business;
use App\Mail\SessionBookedMail;
use App\Mail\SessionCancelledMail;
use App\Mail\SessionCompletedMail;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Ics;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->trainer = User::factory()->create([
        'name' => 'Brooke',
        'business_name' => 'Brooke Fitness',
        'email' => 'brooke@example.com',
        'booking_instructions' => 'Text me at 604-555-0199 to change a session.',
        'notify_on_booking' => true,
        'notify_on_completion' => false,
    ]);
    $this->actingAs($this->trainer);

    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $this->plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 30]);

    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id, 'first_name' => 'Ava', 'last_name' => 'Nguyen', 'email' => 'ava@example.com']);
    $this->ben = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id, 'first_name' => 'Ben', 'last_name' => 'Okafor', 'email' => null]);
});

it('books a session and emails calendar invites to attendees with an email', function () {
    Livewire::test(Log::class, ['mode' => 'book'])
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-09-10')
        ->set('time', '09:00')
        ->call('addClient', $this->ava->id)
        ->call('addClient', $this->ben->id)
        ->call('save', false)
        ->assertHasNoErrors()
        ->assertRedirect();

    $session = TrainingSession::first();
    expect($session->isScheduled())->toBeTrue()
        ->and($session->invitesWereSent())->toBeTrue()
        ->and($session->ics_uid)->not->toBeNull()
        ->and($session->attendees->firstWhere('client_id', $this->ava->id)->invite_sent_at)->not->toBeNull()
        ->and($session->attendees->firstWhere('client_id', $this->ben->id)->invite_sent_at)->toBeNull();

    Mail::assertQueued(SessionBookedMail::class, function (SessionBookedMail $mail) use ($session) {
        return $mail->hasTo('ava@example.com')
            && $mail->client->is($this->ava)
            && $mail->session->is($session)
            && ! $mail->isUpdate;
    });
    Mail::assertQueuedCount(1);
});

it('does not email invites when the option is off', function () {
    Livewire::test(Log::class, ['mode' => 'book'])
        ->set('service_id', (string) $this->service->id)
        ->set('sendInvites', false)
        ->call('addClient', $this->ava->id)
        ->call('save', false)
        ->assertHasNoErrors();

    Mail::assertNothingQueued();
    expect(TrainingSession::first()->invitesWereSent())->toBeFalse();
});

it('rescheduling sends an updated invite with a higher sequence, and cancelling sends a cancellation', function () {
    $session = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-10 09:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $this->ava->id]);

    Livewire::test(Show::class, ['trainingSession' => $session])->call('sendInvites');
    expect($session->fresh()->ics_sequence)->toBe(0);

    Livewire::test(Show::class, ['trainingSession' => $session->fresh()])
        ->set('newDate', '2026-09-11')
        ->set('newTime', '10:30')
        ->set('newDuration', '45')
        ->call('reschedule')
        ->assertHasNoErrors();

    $session->refresh();
    expect($session->starts_at->format('Y-m-d H:i'))->toBe('2026-09-11 10:30')
        ->and($session->duration_minutes)->toBe(45)
        ->and($session->ics_sequence)->toBe(1);

    Mail::assertQueued(SessionBookedMail::class, fn (SessionBookedMail $m) => $m->isUpdate && $m->hasTo('ava@example.com'));

    Livewire::test(Show::class, ['trainingSession' => $session->fresh()])->call('cancel');

    expect($session->fresh()->isCancelled())->toBeTrue();
    Mail::assertQueued(SessionCancelledMail::class, fn (SessionCancelledMail $m) => $m->hasTo('ava@example.com'));
});

it('emails receipts with the charge and remaining balance when completing', function () {
    RecordPayment::class;
    app(RecordPayment::class)->handle($this->ava, 100, PaymentMethod::Cash);

    Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-09-03')
        ->set('time', '08:00')
        ->set('sendReceipts', true)
        ->call('addClient', $this->ava->id)
        ->call('addClient', $this->ben->id)
        ->call('save', true)
        ->assertHasNoErrors();

    $session = TrainingSession::first();
    $attendee = $session->attendees->firstWhere('client_id', $this->ava->id);

    expect($attendee->receipt_sent_at)->not->toBeNull()
        ->and($session->attendees->firstWhere('client_id', $this->ben->id)->receipt_sent_at)->toBeNull();

    Mail::assertQueued(SessionCompletedMail::class, function (SessionCompletedMail $mail) use ($attendee) {
        return $mail->hasTo('ava@example.com') && $mail->attendee->is($attendee);
    });

    $rendered = (new SessionCompletedMail($attendee->fresh(['trainingSession.service', 'trainingSession.trainer', 'client.plan'])))->render();
    expect($rendered)->toContain('$31.50')   // partner rate + GST
        ->toContain('$68.50')                // 100 - 31.50 remaining
        ->toContain('Text me at 604-555-0199');
});

it('renders invite emails with a valid ics attachment', function () {
    $session = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-10 09:00:00', 'duration_minutes' => 60]);
    SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $this->ava->id]);

    $ics = Ics::forSession($session->fresh(), $this->ava);
    $unfolded = str_replace("\r\n ", '', $ics); // undo RFC 5545 line folding for content checks

    expect($unfolded)->toContain("BEGIN:VCALENDAR\r\n")
        ->toContain('METHOD:REQUEST')
        ->toContain('UID:'.$session->fresh()->ics_uid)
        ->toContain('DTSTART:20260910T160000Z') // 09:00 Pacific (PDT) = 16:00 UTC
        ->toContain('DTEND:20260910T170000Z')
        ->toContain('SUMMARY:Personal Training with Brooke Fitness')
        ->toContain('ATTENDEE;CN=Ava Nguyen;ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:ava@example.com')
        ->toContain('ORGANIZER;CN=Brooke Fitness:mailto:brooke@example.com')
        ->toContain('STATUS:CONFIRMED');

    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    $cancel = Ics::forSession($session->fresh(), $this->ava, Ics::METHOD_CANCEL);
    expect($cancel)->toContain('METHOD:CANCEL')->toContain('STATUS:CANCELLED');

    $long = Ics::fold('DESCRIPTION:'.str_repeat('word ', 40));
    expect(str_replace("\r\n ", '', $long))->toBe('DESCRIPTION:'.str_repeat('word ', 40));

    $rendered = (new SessionBookedMail($session->fresh(), $this->ava))->render();
    expect($rendered)->toContain('Your session is booked')
        ->toContain('Thursday, September 10, 2026')
        ->toContain('Text me at 604-555-0199');
});

it('shows the month and week calendar with only this trainer\'s sessions', function () {
    $mine = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-15 07:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $mine->id, 'client_id' => $this->ava->id]);

    $other = User::factory()->create();
    $otherClient = Client::factory()->create(['user_id' => $other->id, 'first_name' => 'Zed', 'last_name' => 'Other']);
    $theirs = TrainingSession::factory()->create(['user_id' => $other->id, 'starts_at' => '2026-09-15 08:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $theirs->id, 'client_id' => $otherClient->id]);

    Livewire::test(Calendar::class, ['date' => '2026-09-01'])
        ->assertSee('September 2026')
        ->assertSee('Ava')
        ->assertDontSee('Zed')
        ->call('setView', 'week')
        ->call('next')
        ->call('next')
        ->assertSee('Sep 14')
        ->assertSee('Ava Nguyen');

    $this->get(route('sessions.calendar'))->assertOk();
    $this->get(route('sessions.book', ['date' => '2026-09-20']))->assertOk()->assertSee('Book session');
});

it('shows a single day with every session on it and steps one day at a time', function () {
    $early = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-15 07:00:00', 'duration_minutes' => 45]);
    SessionAttendee::factory()->create(['training_session_id' => $early->id, 'client_id' => $this->ava->id]);
    $late = TrainingSession::factory()->completed()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-15 18:30:00']);
    SessionAttendee::factory()->create(['training_session_id' => $late->id, 'client_id' => $this->ben->id]);

    $cara = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Cara', 'last_name' => 'Tomorrow']);
    $nextDay = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-16 07:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $nextDay->id, 'client_id' => $cara->id]);

    Livewire::test(Calendar::class, ['date' => '2026-09-15', 'view' => 'day'])
        ->assertSee('Tuesday, September 15, 2026')
        ->assertSee('2 sessions')
        ->assertSee('1 scheduled')
        ->assertSee('1 completed')
        ->assertSee('7:00 am – 7:45 am')
        ->assertSee('6:30 pm – 7:30 pm')
        ->assertSee('Ava Nguyen')
        ->assertSee('Ben Okafor')
        ->assertDontSee('Cara')
        ->call('next')
        ->assertSee('Wednesday, September 16, 2026')
        ->assertSee('Cara Tomorrow')
        ->assertDontSee('Ava Nguyen')
        ->call('next')
        ->assertSee('Thursday, September 17, 2026')
        ->assertSee('No sessions on this day.')
        ->call('previous')
        ->call('previous')
        ->assertSee('Tuesday, September 15, 2026');

    Livewire::test(Calendar::class, ['date' => '2026-09-01'])
        ->assertSee('September 2026')
        ->call('showDay', '2026-09-15')
        ->assertSet('view', 'day')
        ->assertSet('date', '2026-09-15')
        ->assertSee('Tuesday, September 15, 2026');

    $this->get(route('sessions.calendar', ['view' => 'day', 'date' => '2026-09-15']))->assertOk()->assertSee('Tuesday, September 15, 2026');
});

it('saves notification defaults in business settings', function () {
    Livewire::test(Business::class)
        ->set('notify_on_booking', false)
        ->set('notify_on_completion', true)
        ->call('save')
        ->assertHasNoErrors();

    $trainer = $this->trainer->fresh();
    expect($trainer->notify_on_booking)->toBeFalse()
        ->and($trainer->notify_on_completion)->toBeTrue();

    Livewire::test(Log::class)->assertSet('sendReceipts', true);
    Livewire::test(Log::class, ['mode' => 'book'])->assertSet('sendInvites', false);
});
