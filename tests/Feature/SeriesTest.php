<?php

use App\Actions\ApplyAttendeesToFollowing;
use App\Actions\BookSessionSeries;
use App\Actions\CancelFollowing;
use App\Actions\RescheduleFollowing;
use App\Enums\SessionStatus;
use App\Mail\SeriesMail;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\SessionSeries;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Ics;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->trainer = User::factory()->create(['business_name' => 'Brooke Fitness']);
    $this->actingAs($this->trainer);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'PT']);
    $this->gym = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside']);
    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Ava', 'last_name' => 'N', 'email' => 'ava@example.com']);
    $this->ben = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Ben', 'last_name' => 'O', 'email' => null]);
    $this->cat = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Cat', 'last_name' => 'P', 'email' => 'cat@example.com']);

    $this->pattern = [
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'gym_id' => $this->gym->id,
        'starts_on' => '2026-09-08', 'ends_on' => '2026-09-24', 'time' => '09:00', 'duration_minutes' => 60,
        'interval_weeks' => 1, 'weekdays' => [2, 4], 'notes' => null, // Tue & Thu: 8, 10, 15, 17, 22, 24 Sep
    ];
});

it('books a series of sessions and emails one combined invite per client', function () {
    $series = app(BookSessionSeries::class)->handle($this->pattern, [$this->ava->id => [], $this->ben->id => ['price_override' => 25.0]], sendInvites: true);

    expect($series->sessions)->toHaveCount(6)
        ->and($series->describe())->toBe('Every week on Tue & Thu until Sep 24, 2026')
        ->and($series->sessions->first()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-08 09:00')
        ->and($series->sessions->last()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-24 09:00')
        ->and($series->sessions->first()->gym_id)->toBe($this->gym->id)
        ->and($series->sessions->first()->attendees)->toHaveCount(2)
        ->and((float) $series->sessions->first()->attendees->firstWhere('client_id', $this->ben->id)->price_override)->toBe(25.0)
        ->and($series->sessions->every(fn ($s) => $s->invitesWereSent()))->toBeTrue()
        ->and($series->sessions->pluck('ics_uid')->unique())->toHaveCount(6);

    Mail::assertQueuedCount(1); // Ben has no email
    Mail::assertQueued(SeriesMail::class, function (SeriesMail $m) {
        return $m->hasTo('ava@example.com') && $m->kind === SeriesMail::BOOKED && $m->sessions->count() === 6;
    });

    $ics = Ics::forSessions($series->sessions, $this->ava);
    expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(6)
        ->and(substr_count($ics, 'METHOD:REQUEST'))->toBe(1);

    $rendered = (new SeriesMail($series->sessions, $this->ava))->render();
    expect($rendered)->toContain('6 sessions')->toContain('Tue Sep 8, 2026')->toContain('Thu Sep 24, 2026');
});

it('does not email when invites are off', function () {
    app(BookSessionSeries::class)->handle($this->pattern, [$this->ava->id => []], sendInvites: false);
    Mail::assertNothingQueued();
});

it('reschedules this and following only, shifting dates by the same delta', function () {
    $series = app(BookSessionSeries::class)->handle($this->pattern, [$this->ava->id => []], sendInvites: true);
    $sessions = $series->sessions;
    $first = $sessions[0];   // Sep 8
    $anchor = $sessions[2];  // Sep 15
    $sessions[1]->update(['status' => SessionStatus::Completed]); // Sep 10 done: must stay put

    $newGym = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Eastside']);
    $changed = app(RescheduleFollowing::class)->handle($anchor->fresh(), [
        'date' => '2026-09-16', 'time' => '10:30', 'duration_minutes' => 45, 'service_id' => $this->service->id, 'gym_id' => $newGym->id,
    ]);

    expect($changed)->toBe(4)
        ->and($first->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-08 09:00')
        ->and($sessions[1]->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-10 09:00')
        ->and($anchor->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-16 10:30')
        ->and($sessions[3]->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-18 10:30')
        ->and($sessions[5]->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-09-25 10:30')
        ->and($sessions[5]->fresh()->duration_minutes)->toBe(45)
        ->and($sessions[5]->fresh()->gym_id)->toBe($newGym->id)
        ->and($sessions[5]->fresh()->ics_sequence)->toBe(1)
        ->and($first->fresh()->ics_sequence)->toBe(0)
        ->and($series->fresh()->ends_on->toDateString())->toBe('2026-09-25')
        ->and($series->fresh()->time)->toBe('10:30');

    Mail::assertQueued(SeriesMail::class, fn (SeriesMail $m) => $m->kind === SeriesMail::UPDATED && $m->hasTo('ava@example.com') && $m->sessions->count() === 4);
});

it('cancels this and following, ends the series, and emails one cancellation', function () {
    $series = app(BookSessionSeries::class)->handle($this->pattern, [$this->ava->id => [], $this->ben->id => []], sendInvites: true);
    $anchor = $series->sessions[3]; // Sep 17

    expect(app(CancelFollowing::class)->handle($anchor))->toBe(3);

    $fresh = $series->fresh();
    expect($fresh->sessions->take(3)->every(fn ($s) => $s->isScheduled()))->toBeTrue()
        ->and($fresh->sessions->skip(3)->every(fn ($s) => $s->isCancelled()))->toBeTrue()
        ->and($fresh->ends_on->toDateString())->toBe('2026-09-16');

    Mail::assertQueued(SeriesMail::class, fn (SeriesMail $m) => $m->kind === SeriesMail::CANCELLED && $m->hasTo('ava@example.com') && $m->sessions->count() === 3);
    Mail::assertNotQueued(SeriesMail::class, fn (SeriesMail $m) => $m->kind === SeriesMail::CANCELLED && $m->client->is($this->ben));

    // Cancelling from the first session leaves the series with no active dates.
    $again = app(BookSessionSeries::class)->handle($this->pattern, [$this->ava->id => []]);
    app(CancelFollowing::class)->handle($again->sessions->first());
    expect($again->fresh()->ends_on->toDateString())->toBe('2026-09-08')
        ->and(TrainingSession::where('session_series_id', $again->id)->where('status', 'scheduled')->count())->toBe(0);
});

it('applies the attendee list to following sessions and invites newcomers', function () {
    $series = app(BookSessionSeries::class)->handle($this->pattern, [$this->ava->id => []], sendInvites: true);
    $anchor = $series->sessions[2]; // Sep 15
    $anchor->attendees()->create(['client_id' => $this->cat->id, 'attended' => true, 'price_override' => 20]);
    $anchor->attendees()->where('client_id', $this->ava->id)->delete();

    expect(app(ApplyAttendeesToFollowing::class)->handle($anchor->fresh()))->toBe(3);

    foreach ([3, 4, 5] as $i) {
        $attendees = $series->sessions[$i]->fresh()->attendees;
        expect($attendees->pluck('client_id')->all())->toBe([$this->cat->id])
            ->and((float) $attendees->first()->price_override)->toBe(20.0);
    }

    expect($series->sessions[0]->fresh()->attendees->pluck('client_id')->all())->toBe([$this->ava->id]);

    Mail::assertQueued(SeriesMail::class, fn (SeriesMail $m) => $m->kind === SeriesMail::BOOKED && $m->hasTo('cat@example.com') && $m->sessions->count() === 3);
});

it('keeps series private to their trainer', function () {
    app(BookSessionSeries::class)->handle($this->pattern, [$this->ava->id => []]);
    $other = User::factory()->create();
    $this->actingAs($other);

    expect(SessionSeries::count())->toBe(0)->and(TrainingSession::count())->toBe(0);
});
