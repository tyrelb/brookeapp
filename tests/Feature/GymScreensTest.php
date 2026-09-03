<?php

use App\Livewire\Reports\GymUsage;
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

function completedAt($test, ?Gym $gym, string $startsAt, int $people): TrainingSession
{
    $session = TrainingSession::factory()->completed()->create([
        'user_id' => $test->trainer->id, 'service_id' => $test->service->id, 'gym_id' => $gym?->id, 'starts_at' => $startsAt,
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
