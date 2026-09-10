<?php

use App\Actions\CompleteTrainingSession;
use App\Actions\FinalizeGymUsageReport;
use App\Actions\ReopenTrainingSession;
use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GymUsageReportBuilder;
use App\Services\ReportBuilder;
use App\Support\CoverNames;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_registered' => true, 'gst_rate' => 5.00]);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'PT']);
    $this->gym = Gym::factory()->covers()->create(['user_id' => $this->trainer->id, 'name' => 'Soul Fitness']);
});

/** A cover session: the gym's own clients, by name, and no attendee rows at all. */
function coverSession($test, string $startsAt, array $names, ?Gym $gym = null, bool $billable = true, bool $complete = true): TrainingSession
{
    $session = TrainingSession::factory()->cover($names)->create([
        'user_id' => $test->trainer->id,
        'service_id' => $test->service->id,
        'gym_id' => ($gym ?? $test->gym)->id,
        'gym_billable' => $billable,
        'starts_at' => $startsAt,
    ]);

    return $complete ? app(CompleteTrainingSession::class)->handle($session) : $session;
}

it('resolves cover rates by group size, falling back to the nearest lower tier', function () {
    expect($this->gym->coverRateFor(1))->toBe(50.0)
        ->and($this->gym->coverRateFor(2))->toBe(70.0)
        ->and($this->gym->coverRateFor(3))->toBe(70.0)   // falls back, as the usage card does
        ->and($this->gym->coverRateFor(14))->toBe(70.0)  // clamped to MAX_PEOPLE
        ->and($this->gym->coversSessions())->toBeTrue();

    // No max(1, ...) clamp, unlike rateFor(): nobody named must not price as one person.
    expect($this->gym->coverRateFor(0))->toBeNull()
        ->and($this->gym->coverRateFor(-1))->toBeNull();

    $bare = Gym::factory()->create(['user_id' => $this->trainer->id]);
    expect($bare->coversSessions())->toBeFalse()
        ->and($bare->coverRateFor(1))->toBeNull();

    // Cover is not gated on the billing model: a rent-only gym can still pay for cover.
    $rentOnly = Gym::factory()->monthly()->covers()->create(['user_id' => $this->trainer->id]);
    expect($rentOnly->coverRateFor(1))->toBe(50.0)
        ->and($rentOnly->rateFor(1))->toBeNull();
});

it('prices a cover session at completion using the trainer GST rate', function () {
    $session = coverSession($this, '2026-09-04 09:00', ['Ann R.', 'Bo T.']);

    expect((float) $session->cover_subtotal)->toBe(70.0)
        ->and((float) $session->cover_gst_amount)->toBe(3.5)
        ->and((float) $session->cover_gst_rate)->toBe(5.0)
        ->and($session->coverTotal())->toBe(73.5)
        ->and($session->headcount())->toBe(2)
        ->and($session->peopleNames())->toBe(['Ann R.', 'Bo T.'])
        ->and($session->attendees()->count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0);
});

it('charges no GST on cover when the trainer is not GST registered', function () {
    $this->trainer->update(['gst_registered' => false]);

    $session = coverSession($this, '2026-09-04 09:00', ['Ann R.']);

    expect((float) $session->cover_subtotal)->toBe(50.0)
        ->and((float) $session->cover_gst_amount)->toBe(0.0)
        ->and($session->coverTotal())->toBe(50.0);
});

it('refuses to complete a cover session that cannot be priced or placed', function () {
    $noNames = TrainingSession::factory()->cover([])->create([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id,
        'gym_id' => $this->gym->id, 'starts_at' => '2026-09-04 09:00',
    ]);
    expect(fn () => app(CompleteTrainingSession::class)->handle($noNames))
        ->toThrow(BillingException::class, 'at least one person');

    $noGym = TrainingSession::factory()->cover(['Ann'])->create([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id,
        'gym_id' => null, 'starts_at' => '2026-09-04 09:00',
    ]);
    expect(fn () => app(CompleteTrainingSession::class)->handle($noGym))
        ->toThrow(BillingException::class, 'Pick the gym');

    $rateless = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Eastside']);
    $unpriced = TrainingSession::factory()->cover(['Ann'])->create([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id,
        'gym_id' => $rateless->id, 'starts_at' => '2026-09-04 09:00',
    ]);
    expect(fn () => app(CompleteTrainingSession::class)->handle($unpriced))
        ->toThrow(BillingException::class, 'No cover rate is set for Eastside');
    expect($unpriced->refresh()->isScheduled())->toBeTrue()
        ->and($unpriced->cover_subtotal)->toBeNull();

    $mixed = TrainingSession::factory()->cover(['Ann'])->create([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id,
        'gym_id' => $this->gym->id, 'starts_at' => '2026-09-04 09:00',
    ]);
    SessionAttendee::factory()->create([
        'training_session_id' => $mixed->id,
        'client_id' => Client::factory()->create(['user_id' => $this->trainer->id])->id,
    ]);
    expect(fn () => app(CompleteTrainingSession::class)->handle($mixed))
        ->toThrow(BillingException::class, "can't also have your own clients");
});

it('keeps the two GST rates apart when netting the gym statement', function () {
    // The gym charges 13% on what she owes it; she charges 5% on what it owes her.
    $this->gym->update(['gst_rate' => 13.00, 'billing_model' => 'monthly_plus_usage', 'monthly_fee' => 200]);
    $clients = Client::factory()->count(3)->create(['user_id' => $this->trainer->id]);

    foreach ([[1, '2026-09-01 09:00'], [2, '2026-09-02 09:00']] as [$people, $at]) {
        $usage = TrainingSession::factory()->completed()->create([
            'user_id' => $this->trainer->id, 'service_id' => $this->service->id,
            'gym_id' => $this->gym->id, 'starts_at' => $at,
        ]);
        foreach ($clients->take($people) as $client) {
            SessionAttendee::factory()->create(['training_session_id' => $usage->id, 'client_id' => $client->id]);
        }
    }

    coverSession($this, '2026-09-03 09:00', ['Ann', 'Bo']);

    $report = app(GymUsageReportBuilder::class)->build($this->gym->refresh(), 2026, 9);
    $s = $report['summary'];

    // Usage: 18 + 26 + 200 monthly = 244.00, +13% gym GST = 275.72
    expect($s['usage_subtotal'])->toBe(44.0)
        ->and($s['subtotal'])->toBe(244.0)
        ->and($s['gst'])->toBe(31.72)
        ->and($s['total'])->toBe(275.72);

    // Cover: 70.00, +5% her GST = 73.50
    expect($s['cover_subtotal'])->toBe(70.0)
        ->and($s['cover_gst'])->toBe(3.5)
        ->and($s['cover_gst_rate'])->toBe(5.0)
        ->and($s['cover_total'])->toBe(73.5);

    // Netting the GST-inclusive totals: 275.72 - 73.50.
    // Netting pre-tax first would give 196.62 (at 13%) or 182.70 (at 5%) — both wrong,
    // for her and for the gym. Do not "simplify" this into one rate.
    expect($s['net_total'])->toBe(202.22);

    // A cover session is never a usage charge, however the gym bills.
    expect($report['rows'])->toHaveCount(2)
        ->and($s['sessions'])->toBe(2)
        ->and($s['people'])->toBe(3)
        ->and($report['cover_rows'])->toHaveCount(1)
        ->and($report['cover_rows'][0]['names'])->toBe(['Ann', 'Bo']);
});

it('turns the statement negative when the month is all cover', function () {
    coverSession($this, '2026-09-03 09:00', ['Ann', 'Bo']);

    $s = app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9)['summary'];

    expect($s['total'])->toBe(0.0)
        ->and($s['cover_total'])->toBe(73.5)
        ->and($s['net_total'])->toBe(-73.5);
});

it('sums the GST stored on each cover session rather than recomputing it', function () {
    $this->gym->update(['cover_rates' => [2 => 52.50]]);

    coverSession($this, '2026-09-03 09:00', ['Ann', 'Bo']);
    coverSession($this, '2026-09-04 09:00', ['Cal', 'Di']);

    $s = app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9)['summary'];

    // 52.50 * 5% = 2.625, rounded per session to 2.63, so 5.26 across two sessions.
    // Recomputing on the 105.00 subtotal would give 5.25 and disagree with the ledger.
    expect($s['cover_subtotal'])->toBe(105.0)
        ->and($s['cover_gst'])->toBe(5.26)
        ->and($s['cover_total'])->toBe(110.26);
});

it('freezes the cover fee so later rate changes cannot restate a reported month', function () {
    $session = coverSession($this, '2026-09-03 09:00', ['Ann', 'Bo']);

    $this->gym->update(['cover_rates' => [1 => 999, 2 => 999]]);
    $this->trainer->update(['gst_rate' => 20.00]);

    $s = app(GymUsageReportBuilder::class)->build($this->gym->refresh(), 2026, 9)['summary'];
    expect($s['cover_subtotal'])->toBe(70.0)->and($s['cover_gst'])->toBe(3.5);

    $revenue = app(ReportBuilder::class)->monthly($this->trainer->refresh(), 2026, 9)['revenue'];
    expect($revenue['cover_fees'])->toBe(70.0)->and($revenue['cover_gst'])->toBe(3.5);

    // Reopening stops it earning until it is completed again; the names survive.
    app(ReopenTrainingSession::class)->handle($session);
    $session->refresh();
    expect($session->cover_subtotal)->toBeNull()
        ->and($session->cover_gst_amount)->toBeNull()
        ->and($session->coverNames())->toBe(['Ann', 'Bo']);
    expect(app(ReportBuilder::class)->monthly($this->trainer, 2026, 9)['revenue']['cover_fees'])->toBe(0.0);

    // Completing again prices at the card as it stands now.
    app(CompleteTrainingSession::class)->handle($session);
    expect((float) $session->refresh()->cover_subtotal)->toBe(999.0);
});

it('drops an unticked cover session from the credit and from revenue together', function () {
    coverSession($this, '2026-09-03 09:00', ['Ann', 'Bo'], billable: false);
    coverSession($this, '2026-09-04 09:00', ['Cal']);

    $s = app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9)['summary'];
    expect($s['cover_sessions'])->toBe(1)
        ->and($s['cover_sessions_excluded'])->toBe(1)
        ->and($s['cover_people'])->toBe(1)
        ->and($s['cover_subtotal'])->toBe(50.0)
        ->and($s['net_total'])->toBe(-52.5);

    // One switch, one meaning: off the statement means not invoiced means not revenue.
    $revenue = app(ReportBuilder::class)->monthly($this->trainer, 2026, 9)['revenue'];
    expect($revenue['cover_fees'])->toBe(50.0)
        ->and($revenue['cover_sessions'])->toBe(1)
        ->and($revenue['cover_gst'])->toBe(2.5);
});

it('reports cover fees as accrual revenue without touching the cash basis', function () {
    coverSession($this, '2026-09-03 09:00', ['Ann', 'Bo']);

    $report = app(ReportBuilder::class)->monthly($this->trainer, 2026, 9);

    expect($report['revenue']['cover_fees'])->toBe(70.0)
        ->and($report['revenue']['cover_gst'])->toBe(3.5)
        ->and($report['revenue']['total'])->toBe(70.0)
        ->and($report['revenue']['gst'])->toBe(3.5)
        ->and($report['revenue']['total_with_gst'])->toBe(73.5)
        ->and($report['sessions']['count'])->toBe(1)
        ->and($report['sessions']['attendances'])->toBe(2)
        ->and($report['sessions']['by_service']['PT']['revenue'])->toBe(70.0);

    // No money moved: the gym credits it against her invoice.
    expect($report['payments']['total'])->toBe(0.0)
        ->and($report['payments']['gst_embedded'])->toBe(0.0);
});

it('cleans the names typed into a cover session', function () {
    expect(CoverNames::clean(['  Ann ', '', '   ', 'Ann', null, 'Bo']))->toBe(['Ann', 'Ann', 'Bo']);
    expect(CoverNames::clean(array_fill(0, 15, 'X')))->toHaveCount(Gym::MAX_PEOPLE);
});

it('renders a month finalized before cover sessions existed', function () {
    coverSession($this, '2026-09-03 09:00', ['Ann', 'Bo']);
    $finalized = app(FinalizeGymUsageReport::class)->handle($this->gym, 2026, 9);

    // Strip the feature back out of the stored JSON, the way a pre-existing row looks.
    $old = $finalized->snapshot;
    unset($old['cover_rows'], $old['gym']['covers_sessions']);
    foreach (['cover_sessions', 'cover_sessions_excluded', 'cover_people', 'cover_by_people',
        'cover_subtotal', 'cover_gst', 'cover_gst_rate', 'cover_total', 'net_total'] as $key) {
        unset($old['summary'][$key]);
    }
    $finalized->update(['snapshot' => $old]);

    $snapshot = $finalized->refresh()->normalizedSnapshot();

    expect($snapshot['cover_rows'])->toBe([])
        ->and($snapshot['summary']['cover_total'])->toBe(0.0)
        ->and($snapshot['summary']['net_total'])->toBe($snapshot['summary']['total'])
        ->and($snapshot['gym']['covers_sessions'])->toBeFalse();
});

it('keeps another trainer\'s cover sessions out of both reports', function () {
    $other = User::factory()->create();
    $otherGym = Gym::factory()->covers()->create(['user_id' => $other->id]);
    TrainingSession::factory()->cover(['Someone'])->completed()->create([
        'user_id' => $other->id,
        'service_id' => Service::factory()->create(['user_id' => $other->id])->id,
        'gym_id' => $otherGym->id,
        'starts_at' => '2026-09-03 09:00',
        'cover_subtotal' => 50, 'cover_gst_amount' => 2.5, 'cover_gst_rate' => 5,
    ]);

    expect(app(GymUsageReportBuilder::class)->build($this->gym, 2026, 9)['cover_rows'])->toBe([]);
    expect(app(ReportBuilder::class)->monthly($this->trainer, 2026, 9)['revenue']['cover_fees'])->toBe(0.0);
});
