<?php

use App\Actions\CompleteTrainingSession;
use App\Livewire\Reports\GymUsage;
use App\Livewire\Sessions\BulkLog;
use App\Livewire\Sessions\Log;
use App\Livewire\Sessions\Show;
use App\Livewire\Settings\Gyms;
use App\Models\Client;
use App\Models\Gym;
use App\Models\GymUsageReport;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GymUsageReportBuilder;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create();
    $this->actingAs($this->trainer);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'PT']);
    $this->plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 30]);
    $this->clients = Client::factory()->count(3)->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id]);
});

function completedAt($test, ?Gym $gym, string $startsAt, int $people, int $minutes = 60): TrainingSession
{
    $session = TrainingSession::factory()->completed()->create([
        'user_id' => $test->trainer->id, 'service_id' => $test->service->id, 'gym_id' => $gym?->id, 'starts_at' => $startsAt, 'duration_minutes' => $minutes,
    ]);
    foreach ($test->clients->take($people) as $client) {
        SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $client->id]);
    }

    return $session;
}

it('adds gyms in settings, assigns existing sessions to the first one, and caps active gyms at three', function () {
    $existing = completedAt($this, null, '2026-09-01 07:00:00', 1);

    Livewire::test(Gyms::class)
        ->call('create')
        ->set('name', 'Westside')
        ->set('billing_model', 'monthly_plus_usage')
        ->set('monthly_fee', '150')
        ->set('rates.2', '30')
        ->set('assignExisting', true)
        ->call('save')
        ->assertHasNoErrors();

    $gym = Gym::first();
    expect($gym->name)->toBe('Westside')
        ->and($gym->is_default)->toBeTrue()
        ->and((float) $gym->monthly_fee)->toBe(150.0)
        ->and($gym->rateFor(2))->toBe(30.0)
        ->and($gym->rateFor(1))->toBe(18.0)
        ->and($existing->fresh()->gym_id)->toBe($gym->id);

    // Monthly-only needs a fee; usage-only needs a single-person rate.
    Livewire::test(Gyms::class)->call('create')->set('name', 'Bad')->set('billing_model', 'monthly')->set('monthly_fee', '')->call('save')->assertHasErrors(['monthly_fee']);
    Livewire::test(Gyms::class)->call('create')->set('name', 'Bad')->set('billing_model', 'usage')->set('rates.1', '')->call('save')->assertHasErrors(['rates.1']);

    Gym::factory()->count(2)->create(['user_id' => $this->trainer->id]);
    Livewire::test(Gyms::class)->call('create')->set('name', 'Fourth')->set('billing_model', 'usage')->call('save')->assertHasErrors(['active']);
    expect(Gym::count())->toBe(3);

    $this->get(route('settings.gyms'))->assertOk()->assertSee('Westside');
});

it('defaults new sessions to the default gym and lets a session move gyms', function () {
    $gym = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);
    $other = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Eastside']);

    Livewire::test(Log::class)
        ->assertSet('gym_id', (string) $gym->id)
        ->assertSee('Eastside')
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->clients[0]->id)
        ->call('save', true)
        ->assertHasNoErrors();

    $session = TrainingSession::first();
    expect($session->gym_id)->toBe($gym->id);

    Livewire::test(Show::class, ['trainingSession' => $session])
        ->call('setGym', (string) $other->id)
        ->call('toggleGymBillable');

    expect($session->fresh()->gym_id)->toBe($other->id)
        ->and($session->fresh()->gym_billable)->toBeFalse();
});

it('shows the gym usage report, toggles rows, assigns unassigned sessions, finalizes, reopens and exports', function () {
    $gym = Gym::factory()->monthlyPlusUsage(100)->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);
    $single = completedAt($this, $gym, '2026-09-01 07:00:00', 1);
    $partner = completedAt($this, $gym, '2026-09-02 08:00:00', 2);
    $orphan = completedAt($this, null, '2026-09-03 09:00:00', 3);

    $page = Livewire::test(GymUsage::class, ['month' => '2026-09'])
        ->assertSet('gym', (string) $gym->id)
        ->assertSee('Westside')
        ->assertSee('$44.00')      // 18 + 26 usage
        ->assertSee('$151.20')     // (44 + 100) * 1.05
        ->assertSee('1 completed session in September 2026 has no gym');

    $page->call('toggleRow', $single->id)
        ->assertSee('$26.00')
        ->assertSee('$132.30');    // (26 + 100) * 1.05
    expect($single->fresh()->gym_billable)->toBeFalse();

    $page->call('assignUnassigned');
    expect($orphan->fresh()->gym_id)->toBe($gym->id);
    $page->assertSee('$61.00');   // 26 + 35

    $page->call('finalize');
    $report = GymUsageReport::first();
    expect($report->period)->toBe('2026-09')
        ->and($report->snapshot['summary']['total'])->toBe(169.05); // (61 + 100) * 1.05

    // Locked: toggling does nothing and the snapshot is what renders.
    $page->call('toggleRow', $partner->id)->assertSee('Finalized');
    expect($partner->fresh()->gym_billable)->toBeTrue();
    completedAt($this, $gym, '2026-09-10 07:00:00', 1);
    Livewire::test(GymUsage::class, ['month' => '2026-09'])->assertSee('$169.05');

    $page->call('reopen');
    expect(GymUsageReport::count())->toBe(0);
    Livewire::test(GymUsage::class, ['month' => '2026-09'])->assertSee('Draft')->assertSee('$187.95'); // (61 + 18 + 100) * 1.05

    Livewire::test(GymUsage::class, ['month' => '2026-09'])->call('exportCsv')->assertFileDownloaded('gym-usage-westside-2026-09.csv');
    $this->get(route('reports.gym-usage', ['month' => '2026-09']))->assertOk()->assertSee('Gym usage report');
});

it('renders an empty state without gyms and hides other trainers\' gyms', function () {
    Livewire::test(GymUsage::class)->assertSee('No gym set up yet');

    $other = User::factory()->create();
    $theirs = Gym::factory()->create(['user_id' => $other->id, 'name' => 'Private Gym']);

    Livewire::test(GymUsage::class, ['gym' => (string) $theirs->id])->assertDontSee('Private Gym');
});

it('saves a cover rate card independently of how the gym bills the trainer', function () {
    Livewire::test(Gyms::class)
        ->call('create')
        ->set('name', 'Soul Fitness')
        ->set('billing_model', 'monthly')   // rent-only, yet it can still pay for cover
        ->set('monthly_fee', '300')
        ->set('covers_clients', true)
        ->set('coverRates.1', '50')
        ->set('coverRates.2', '70')
        ->call('save')
        ->assertHasNoErrors();

    $gym = Gym::query()->where('name', 'Soul Fitness')->firstOrFail();
    expect($gym->cover_rates)->toEqual([1 => 50, 2 => 70])
        ->and($gym->coverRateFor(1))->toBe(50.0)
        ->and($gym->coverRateFor(2))->toBe(70.0)
        ->and($gym->usage_rates)->toBeNull();   // the monthly-only gate must not wipe the cover card

    // Ticking the box means at least the one-person rate is needed.
    Livewire::test(Gyms::class)
        ->call('edit', $gym->id)->set('coverRates.1', '')->call('save')->assertHasErrors(['coverRates.1']);

    // Unticking clears the card.
    Livewire::test(Gyms::class)
        ->call('edit', $gym->id)->set('covers_clients', false)->call('save')->assertHasNoErrors();
    expect($gym->refresh()->cover_rates)->toBeNull();
});

it('logs a cover session with free-text names and charges nobody', function () {
    $gym = Gym::factory()->covers()->create(['user_id' => $this->trainer->id, 'name' => 'Soul Fitness', 'is_default' => true]);

    Livewire::test(Log::class)
        ->set('cover', true)
        ->set('service_id', $this->service->id)
        ->set('gym_id', $gym->id)
        ->set('date', '2026-09-04')
        ->set('time', '09:00')
        ->set('coverNames', ['Ann R.', ' Bo T. '])
        ->call('save', true)
        ->assertHasNoErrors();

    $session = TrainingSession::query()->cover()->firstOrFail();
    expect($session->cover_names)->toBe(['Ann R.', 'Bo T.'])
        ->and((float) $session->cover_subtotal)->toBe(70.0)
        ->and((float) $session->cover_gst_amount)->toBe(3.5)
        ->and($session->attendees()->count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0);

    Livewire::test(Show::class, ['trainingSession' => $session])
        ->assertOk()
        ->assertSee('Ann R.')
        ->assertSee('Covering Soul Fitness');
});

it('rejects a cover session with no names or no agreed rate', function () {
    $gym = Gym::factory()->covers()->create(['user_id' => $this->trainer->id]);
    $rateless = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Eastside']);

    $form = fn (int $gymId, array $names) => Livewire::test(Log::class)
        ->set('cover', true)
        ->set('service_id', $this->service->id)
        ->set('gym_id', $gymId)
        ->set('date', '2026-09-04')
        ->set('time', '09:00')
        ->set('coverNames', $names)
        ->call('save', true);

    $form($gym->id, ['', '  '])->assertHasErrors(['coverNames']);
    $form($rateless->id, ['Ann'])->assertHasErrors(['coverNames']);

    expect(TrainingSession::query()->cover()->count())->toBe(0);
});

it('shows the cover credit and a negative total on the gym usage report', function () {
    $gym = Gym::factory()->covers()->create(['user_id' => $this->trainer->id, 'name' => 'Soul Fitness']);

    $session = TrainingSession::factory()->cover(['Ann R.', 'Bo T.'])->create([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id,
        'gym_id' => $gym->id, 'starts_at' => '2026-09-04 09:00',
    ]);
    app(CompleteTrainingSession::class)->handle($session);

    Livewire::test(GymUsage::class, ['month' => '2026-09', 'gym' => (string) $gym->id])
        ->assertOk()
        ->assertSee('Covering Soul Fitness')
        ->assertSee('Ann R., Bo T.')
        ->assertSee('Soul Fitness owes you')
        ->assertSee('$73.50')
        ->call('exportCsv')
        ->assertFileDownloaded('gym-usage-soul-fitness-2026-09.csv');
});

it('renders every screen that lists sessions when one has no attendees', function () {
    $gym = Gym::factory()->covers()->create(['user_id' => $this->trainer->id]);
    $session = TrainingSession::factory()->cover(['Ann R.'])->create([
        'user_id' => $this->trainer->id, 'service_id' => $this->service->id,
        'gym_id' => $gym->id, 'starts_at' => now()->startOfMonth()->addDays(2)->setTime(9, 0),
    ]);
    app(CompleteTrainingSession::class)->handle($session);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('sessions.index'))->assertOk();
    $this->get(route('sessions.show', $session))->assertOk();
    $this->get(route('sessions.calendar'))->assertOk();
    $this->get(route('reports.monthly'))->assertOk()->assertSee('$50.00');
    $this->get(route('reports.annual'))->assertOk();
});

it('books a cover session for later without pricing it yet', function () {
    $gym = Gym::factory()->covers()->create(['user_id' => $this->trainer->id, 'name' => 'Soul Fitness']);

    Livewire::test(Log::class, ['mode' => 'book'])
        ->set('cover', true)
        ->set('service_id', $this->service->id)
        ->set('gym_id', $gym->id)
        ->set('date', '2026-10-04')
        ->set('time', '09:00')
        ->set('coverNames', ['Rita P.'])
        ->call('save', false)
        ->assertHasNoErrors();

    $session = TrainingSession::query()->cover()->firstOrFail();
    expect($session->isScheduled())->toBeTrue()
        ->and($session->cover_names)->toBe(['Rita P.'])
        ->and($session->cover_subtotal)->toBeNull();   // priced only on completion

    // Ticking cover clears any clients already picked, and drops the repeat option.
    $form = Livewire::test(Log::class, ['mode' => 'book'])
        ->set('attendees', [$this->clients->first()->id => ['attended' => true]])
        ->set('repeat', true)
        ->set('cover', true);

    expect($form->get('attendees'))->toBe([])
        ->and($form->get('repeat'))->toBeFalse();
});

it('pro-rates the usage report by session length', function () {
    $gym = Gym::factory()->noGst()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);
    completedAt($this, $gym, '2026-09-01 07:00:00', 1, 90);

    Livewire::test(GymUsage::class, ['month' => '2026-09'])
        ->assertSee('90 min')
        ->assertSee('$18.00')   // the hourly rate, shown so the charge is explicable
        ->assertSee('$27.00');  // what the session actually costs
});

it('renders a snapshot finalized before usage went hourly', function () {
    $gym = Gym::factory()->noGst()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);

    // The old shape: no minutes, no amount — rate WAS the charge.
    GymUsageReport::create([
        'user_id' => $this->trainer->id,
        'gym_id' => $gym->id,
        'period' => '2026-08',
        'finalized_at' => now(),
        'snapshot' => [
            'gym' => ['id' => $gym->id, 'name' => $gym->name, 'billing_model' => $gym->billing_model->value, 'charges_gst' => false, 'gst_rate' => 0.0],
            'period' => '2026-08',
            'label' => 'August 2026',
            'rows' => [[
                'session_id' => 1, 'date' => '2026-08-03', 'time' => '07:00', 'service' => 'PT',
                'attendees' => ['Someone'], 'people' => 1, 'rate' => 18.0, 'billable' => true,
            ]],
            'summary' => [
                'sessions' => 1, 'sessions_excluded' => 0, 'people' => 1,
                'by_people' => [1 => ['sessions' => 1, 'rate' => 18.0, 'amount' => 18.0]],
                'usage_subtotal' => 18.0, 'monthly_fee' => null, 'subtotal' => 18.0, 'gst' => 0.0, 'total' => 18.0,
            ],
            'unassigned' => 0,
        ],
    ]);

    Livewire::test(GymUsage::class, ['month' => '2026-08'])
        ->assertOk()
        ->assertSee('$18.00');
});

it('previews what the gym charges while logging a session', function () {
    Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);

    Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->clients[0]->id)
        ->set('duration_minutes', '90')
        ->assertSee('You pay Westside')
        ->assertSee('$27.00')
        ->set('duration_minutes', '60')
        ->assertSee('$18.00');
});

it('says nothing about per-session cost for a monthly-only gym', function () {
    Gym::factory()->monthly(450)->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);

    Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->clients[0]->id)
        ->set('duration_minutes', '90')
        ->assertDontSee('You pay Westside')
        ->assertSee('flat monthly rate');
});

it('quotes no gym figure until someone is actually attending', function () {
    Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);

    Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->set('duration_minutes', '90')
        ->assertSee('Add clients to see what Westside charges you')
        ->assertDontSee('$27.00');
});

it('totals the gym cost across every date in a bulk log', function () {
    Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);

    Livewire::test(BulkLog::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->clients[0]->id)
        ->set('duration_minutes', '90')
        ->call('toggleDate', '2026-06-03')
        ->call('toggleDate', '2026-06-10')
        ->assertSee('You pay Westside, per session')
        ->assertSee('$27.00')   // one session
        ->assertSee('$54.00');  // both dates
});

it('shows what a logged session cost at the gym on its own page', function () {
    $gym = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);
    $session = completedAt($this, $gym, '2026-09-01 07:00:00', 1, 90);

    Livewire::test(Show::class, ['trainingSession' => $session])
        ->assertOk()
        ->assertSee('Costs you $27.00')
        ->assertSee('$18.00/hour × 90 min');
});

it('exports the length and hourly rate in the usage csv', function () {
    $gym = Gym::factory()->noGst()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);
    completedAt($this, $gym, '2026-09-01 07:00:00', 1, 90);

    $component = Livewire::test(GymUsage::class, ['month' => '2026-09'])->instance();
    $response = $component->exportCsv(app(GymUsageReportBuilder::class));

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('"# of people",Minutes,"Rate per hour","$ for the session"')
        ->and($csv)->toContain('1,90,18.00,27.00')   // people, minutes, hourly rate, pro-rated charge
        ->and($csv)->toContain('Hours,1.50');
});
