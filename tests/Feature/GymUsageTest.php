<?php

use App\Actions\FinalizeGymUsageReport;
use App\Actions\ReopenGymUsageReport;
use App\Models\Client;
use App\Models\Gym;
use App\Models\GymUsageReport;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\GymUsageReportBuilder;

beforeEach(function () {
    $this->trainer = User::factory()->create();
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'PT']);
    $this->gym = Gym::factory()->monthlyPlusUsage(200)->create(['user_id' => $this->trainer->id, 'name' => 'Westside']);
    $this->clients = Client::factory()->count(6)->create(['user_id' => $this->trainer->id]);
});

/**
 * Creates a completed session at the gym with N attendees (optionally some no-shows).
 */
function gymSession($test, string $startsAt, int $people, int $noShows = 0, ?Gym $gym = null, bool $billable = true, int $minutes = 60): TrainingSession
{
    $session = TrainingSession::factory()->completed()->create([
        'user_id' => $test->trainer->id,
        'service_id' => $test->service->id,
        'gym_id' => ($gym ?? $test->gym)->id,
        'gym_billable' => $billable,
        'starts_at' => $startsAt,
        'duration_minutes' => $minutes,
    ]);

    foreach ($test->clients->take($people + $noShows)->values() as $i => $client) {
        SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $client->id, 'attended' => $i < $people]);
    }

    return $session;
}

it('resolves hourly rates by group size with a cap at ten', function () {
    expect($this->gym->rateFor(1))->toBe(18.0)
        ->and($this->gym->rateFor(2))->toBe(26.0)
        ->and($this->gym->rateFor(5))->toBe(52.0)
        ->and($this->gym->rateFor(6))->toBe(70.0)
        ->and($this->gym->rateFor(10))->toBe(70.0)
        ->and($this->gym->rateFor(14))->toBe(70.0)
        ->and($this->gym->rateFor(0))->toBe(18.0);

    $sparse = Gym::factory()->create(['user_id' => $this->trainer->id, 'usage_rates' => [1 => 20, 4 => 40]]);
    expect($sparse->rateFor(3))->toBe(20.0)->and($sparse->rateFor(9))->toBe(40.0);

    $monthlyOnly = Gym::factory()->monthly()->create(['user_id' => $this->trainer->id]);
    expect($monthlyOnly->rateFor(3))->toBeNull();
});

it('pro-rates the hourly rate by how long the session ran', function () {
    expect($this->gym->chargeFor(1, 60))->toBe(18.0)
        ->and($this->gym->chargeFor(1, 90))->toBe(27.0)
        ->and($this->gym->chargeFor(2, 90))->toBe(39.0)
        ->and($this->gym->chargeFor(1, 30))->toBe(9.0)
        ->and($this->gym->chargeFor(1, 45))->toBe(13.5)
        ->and($this->gym->chargeFor(1, 5))->toBe(1.5)      // the form's minimum
        ->and($this->gym->chargeFor(1, 480))->toBe(144.0)  // the form's maximum
        ->and($this->gym->chargeFor(6, 90))->toBe(105.0)
        ->and($this->gym->chargeFor(14, 90))->toBe(105.0)  // above MAX_PEOPLE
        ->and($this->gym->chargeFor(0, 90))->toBe(27.0)    // clamped up to one person
        ->and($this->gym->chargeFor(3, 25))->toBe(14.58);  // 35 * 25/60 = 14.583…

    $sparse = Gym::factory()->create(['user_id' => $this->trainer->id, 'usage_rates' => [1 => 20, 4 => 40]]);
    expect($sparse->chargeFor(3, 90))->toBe(30.0)->and($sparse->chargeFor(9, 30))->toBe(20.0);

    $monthlyOnly = Gym::factory()->monthly()->create(['user_id' => $this->trainer->id]);
    expect($monthlyOnly->chargeFor(3, 90))->toBeNull();
});

it('builds a monthly usage report with rows, a by-size summary, monthly fee and gst', function () {
    gymSession($this, '2026-09-01 07:00:00', 1);
    gymSession($this, '2026-09-02 08:00:00', 2, noShows: 1);          // partner: no-show not counted
    gymSession($this, '2026-09-03 09:00:00', 6);                      // 6-10 tier
    gymSession($this, '2026-09-04 10:00:00', 1, billable: false);     // excluded
    gymSession($this, '2026-10-01 07:00:00', 1);                      // next month

    $otherGym = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Eastside']);
    gymSession($this, '2026-09-05 07:00:00', 3, gym: $otherGym);      // other gym
    TrainingSession::factory()->completed()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'gym_id' => null, 'starts_at' => '2026-09-06 07:00:00']); // unassigned
    TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'gym_id' => $this->gym->id, 'starts_at' => '2026-09-07 07:00:00']); // scheduled, ignored

    $other = User::factory()->create();
    $theirGym = Gym::factory()->create(['user_id' => $other->id]);
    TrainingSession::factory()->completed()->create(['user_id' => $other->id, 'gym_id' => $theirGym->id, 'starts_at' => '2026-09-08 07:00:00']);

    $r = app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9);

    expect($r['label'])->toBe('September 2026')
        ->and($r['rows'])->toHaveCount(4)
        ->and($r['rows'][1]['people'])->toBe(2)
        ->and($r['rows'][1]['rate'])->toBe(26.0)
        ->and($r['rows'][1]['minutes'])->toBe(60)
        ->and($r['rows'][1]['amount'])->toBe(26.0)
        ->and($r['rows'][1]['time'])->toBe('08:00')
        ->and($r['rows'][3]['billable'])->toBeFalse()
        ->and($r['summary']['sessions'])->toBe(3)
        ->and($r['summary']['sessions_excluded'])->toBe(1)
        ->and($r['summary']['people'])->toBe(9)
        ->and($r['summary']['minutes'])->toBe(180)
        ->and($r['summary']['by_people'])->toBe([
            1 => ['sessions' => 1, 'rate' => 18.0, 'minutes' => 60, 'amount' => 18.0],
            2 => ['sessions' => 1, 'rate' => 26.0, 'minutes' => 60, 'amount' => 26.0],
            6 => ['sessions' => 1, 'rate' => 70.0, 'minutes' => 60, 'amount' => 70.0],
        ])
        ->and($r['summary']['usage_subtotal'])->toBe(114.0)
        ->and($r['summary']['monthly_fee'])->toBe(200.0)
        ->and($r['summary']['subtotal'])->toBe(314.0)
        ->and($r['summary']['gst'])->toBe(15.7)
        ->and($r['summary']['total'])->toBe(329.7)
        ->and($r['unassigned'])->toBe(1);
});

it('bills each session pro-rata by its length', function () {
    gymSession($this, '2026-09-01 07:00:00', 1, minutes: 90);   // 18/hr -> 27.00
    gymSession($this, '2026-09-02 08:00:00', 1, minutes: 30);   // 18/hr ->  9.00
    gymSession($this, '2026-09-03 09:00:00', 2, minutes: 45);   // 26/hr -> 19.50

    $r = app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9);

    expect($r['rows'][0]['minutes'])->toBe(90)
        ->and($r['rows'][0]['rate'])->toBe(18.0)      // the rate stays hourly
        ->and($r['rows'][0]['amount'])->toBe(27.0)    // the charge is pro-rated
        ->and($r['summary']['usage_subtotal'])->toBe(55.5)
        ->and($r['summary']['minutes'])->toBe(165)
        ->and($r['summary']['by_people'][1])->toBe(['sessions' => 2, 'rate' => 18.0, 'minutes' => 120, 'amount' => 36.0])
        // The summary must be the sum of the rows, not an independent recomputation,
        // or the two tables on the report stop agreeing with each other.
        ->and($r['summary']['usage_subtotal'])->toBe(round(collect($r['rows'])->where('billable', true)->sum('amount'), 2));
});

it('respects the billing model and the gst switch', function () {
    $usageOnly = Gym::factory()->noGst()->create(['user_id' => $this->trainer->id, 'name' => 'Usage only']);
    $monthlyOnly = Gym::factory()->monthly(450)->create(['user_id' => $this->trainer->id, 'name' => 'Monthly only']);
    gymSession($this, '2026-09-01 07:00:00', 2, gym: $usageOnly);
    gymSession($this, '2026-09-01 09:00:00', 2, gym: $monthlyOnly);

    $u = app(GymUsageReportBuilder::class)->build($usageOnly, 2026, 9);
    expect($u['summary']['monthly_fee'])->toBeNull()
        ->and($u['summary']['subtotal'])->toBe(26.0)
        ->and($u['summary']['gst'])->toBe(0.0)
        ->and($u['summary']['total'])->toBe(26.0);

    $m = app(GymUsageReportBuilder::class)->build($monthlyOnly, 2026, 9);
    expect($m['rows'][0]['rate'])->toBeNull()
        ->and($m['rows'][0]['amount'])->toBeNull()
        ->and($m['rows'][0]['minutes'])->toBe(60)
        ->and($m['summary']['people'])->toBe(2)
        ->and($m['summary']['usage_subtotal'])->toBe(0.0)
        ->and($m['summary']['subtotal'])->toBe(450.0)
        ->and($m['summary']['gst'])->toBe(22.5)
        ->and($m['summary']['total'])->toBe(472.5);
});

it('finalizes a snapshot that survives later changes, and reopens', function () {
    gymSession($this, '2026-09-01 07:00:00', 1);

    $report = app(FinalizeGymUsageReport::class)->handle($this->gym, 2026, 9);
    expect($report->period)->toBe('2026-09')
        ->and($report->snapshot['summary']['total'])->toBe(228.9) // (18 + 200) * 1.05
        ->and(GymUsageReport::count())->toBe(1);

    gymSession($this, '2026-09-02 07:00:00', 4);
    expect($report->fresh()->snapshot['rows'])->toHaveCount(1)
        ->and(app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9)['rows'])->toHaveCount(2);

    // Finalizing again replaces the snapshot rather than duplicating.
    app(FinalizeGymUsageReport::class)->handle($this->gym, 2026, 9);
    expect(GymUsageReport::count())->toBe(1)
        ->and(GymUsageReport::first()->snapshot['rows'])->toHaveCount(2);

    expect(app(ReopenGymUsageReport::class)->handle($this->gym, '2026-09'))->toBeTrue()
        ->and(GymUsageReport::count())->toBe(0);
});

it('freezes a pro-rated snapshot even when the session is later re-timed', function () {
    $session = gymSession($this, '2026-09-01 07:00:00', 1);   // 60 min -> 18.00

    $report = app(FinalizeGymUsageReport::class)->handle($this->gym, 2026, 9);
    $session->update(['duration_minutes' => 90]);

    // toEqual, not toBe: the snapshot is JSON, so a whole-dollar float comes back an int.
    expect($report->fresh()->snapshot['summary']['usage_subtotal'])->toEqual(18.0)
        ->and(app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9)['summary']['usage_subtotal'])->toBe(27.0);
});

it('picks the default gym for new sessions', function () {
    expect($this->trainer->defaultGym()?->id)->toBe($this->gym->id); // only active gym

    $second = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Second']);
    expect($this->trainer->defaultGym())->toBeNull(); // two gyms, none flagged

    $second->update(['is_default' => true]);
    expect($this->trainer->defaultGym()?->id)->toBe($second->id);
});

it('hides other trainers\' gyms', function () {
    $other = User::factory()->create();
    $theirs = Gym::factory()->create(['user_id' => $other->id]);

    $this->actingAs($this->trainer);
    expect(Gym::count())->toBe(1)->and(Gym::find($theirs->id))->toBeNull();
});
