<?php

use App\Actions\BookSessionSeries;
use App\Actions\CompleteTrainingSession;
use App\Actions\LogSessionsInBulk;
use App\Actions\RecordPayment;
use App\Actions\ReopenTrainingSession;
use App\Enums\PaymentMethod;
use App\Exceptions\BillingException;
use App\Livewire\Clients\Form as ClientForm;
use App\Livewire\Sessions\Log;
use App\Models\Client;
use App\Models\FamilyMember;
use App\Models\Gym;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\GymUsageReportBuilder;
use App\Services\ReportBuilder;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'gst_registered' => true]);
    $this->actingAs($this->trainer);

    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);

    $this->familyPlan = Plan::factory()->family()->create(['user_id' => $this->trainer->id]);
    foreach ([1 => 60, 2 => 45, 3 => 25, 4 => 22] as $headcount => $price) {
        PlanRate::factory()->create(['plan_id' => $this->familyPlan->id, 'service_id' => $this->service->id, 'headcount' => $headcount, 'unit_price' => $price]);
    }

    $this->barnes = Client::factory()->create([
        'user_id' => $this->trainer->id,
        'plan_id' => $this->familyPlan->id,
        'first_name' => 'Barnes',
        'last_name' => 'Family',
    ]);

    $this->members = collect(['Mom', 'Dad', 'Ellie', 'Sam', 'Alex'])->map(fn ($name, $i) => FamilyMember::factory()->create([
        'user_id' => $this->trainer->id,
        'client_id' => $this->barnes->id,
        'name' => $name,
        'sort_order' => $i,
    ]))->keyBy('name');
});

/** Books a session and puts the named family members on it. */
function familySession($test, array $attending, ?string $startsAt = null): TrainingSession
{
    $session = TrainingSession::factory()->create([
        'user_id' => $test->trainer->id,
        'service_id' => $test->service->id,
        'starts_at' => $startsAt ?? '2026-09-01 09:00:00',
    ]);

    $attendee = SessionAttendee::factory()->create([
        'training_session_id' => $session->id,
        'client_id' => $test->barnes->id,
    ]);

    $attendee->setRelation('client', $test->barnes);
    $attendee->syncMembers($test->members->mapWithKeys(fn ($m) => [
        $m->id => ['attended' => in_array($m->name, $attending, true), 'price_override' => null],
    ])->all());

    return $session->refresh();
}

it('charges the family wallet once for each member who trained', function () {
    app(RecordPayment::class)->handle($this->barnes, 500, PaymentMethod::Cash);
    $session = familySession($this, ['Mom', 'Dad', 'Ellie']);

    app(CompleteTrainingSession::class)->handle($session);

    $charges = WalletTransaction::query()->where('training_session_id', $session->id)->get();

    expect($session->fresh()->headcount())->toBe(3)
        ->and($charges)->toHaveCount(1)                       // one wallet, one charge
        ->and((float) $charges->first()->subtotal)->toBe(75.00) // 3 × the $25 triple rate
        ->and((float) $charges->first()->gst_amount)->toBe(3.75)
        ->and((float) $charges->first()->amount)->toBe(-78.75)
        ->and($charges->first()->description)->toBe('Personal Training (Triple) — Mom, Dad, Ellie')
        ->and($this->barnes->balance())->toBe(421.25);
});

it('counts a family and a solo client as one group for the rate tier', function () {
    $wallet = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $wallet->id, 'service_id' => $this->service->id, 'headcount' => 3, 'unit_price' => 30]);
    $dana = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $wallet->id]);

    $session = familySession($this, ['Mom', 'Dad']);
    SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $dana->id]);

    app(CompleteTrainingSession::class)->handle($session->refresh());

    expect($session->fresh()->headcount())->toBe(3)
        ->and($this->barnes->balance())->toBe(-52.50)  // 2 × $25 triple + GST
        ->and($dana->balance())->toBe(-31.50);         // her own plan's triple rate
});

it('bills groups larger than the rate grid at the quad rate', function () {
    $session = familySession($this, ['Mom', 'Dad', 'Ellie', 'Sam', 'Alex']);

    app(CompleteTrainingSession::class)->handle($session);

    expect($session->fresh()->headcount())->toBe(5)
        ->and(Plan::headcountLabel(5))->toBe('5 people')
        ->and(Plan::rateTierLabel(5))->toBe('Quad')
        ->and($this->barnes->balance())->toBe(-115.50); // 5 × $22 + GST
});

it('lets one member be priced differently for one session', function () {
    $session = familySession($this, ['Mom', 'Dad', 'Ellie']);
    $attendee = $session->attendees->first();
    $attendee->members()->where('family_member_id', $this->members['Ellie']->id)->update(['price_override' => 10]);

    app(CompleteTrainingSession::class)->handle($session->refresh());

    expect($this->barnes->balance())->toBe(-63.00) // 25 + 25 + 10, plus GST
        ->and((float) $attendee->members()->where('family_member_id', $this->members['Ellie']->id)->first()->subtotal)->toBe(10.00);
});

it('will not complete a family session with nobody ticked', function () {
    $session = familySession($this, []);
    $session->attendees->first()->update(['attended' => true]);

    app(CompleteTrainingSession::class)->handle($session->refresh());
})->throws(BillingException::class, 'Tick which Barnes Family members attended');

it('leaves a family off the headcount when no member turned up', function () {
    $session = familySession($this, []);

    expect($session->fresh()->headcount())->toBe(0)
        ->and($session->attendees->first()->attended)->toBeFalse();
});

it('unwinds a family charge on reopen and recharges the corrected list', function () {
    $session = familySession($this, ['Mom', 'Dad', 'Ellie']);
    app(CompleteTrainingSession::class)->handle($session);

    app(ReopenTrainingSession::class)->handle($session->fresh());

    expect(WalletTransaction::query()->where('training_session_id', $session->id)->whereNull('voided_at')->count())->toBe(0)
        ->and($this->barnes->balance())->toBe(0.0)
        ->and($session->fresh()->attendees->first()->members->where('attended', true))->toHaveCount(3); // the selection survives

    $attendee = $session->fresh()->attendees->first();
    $attendee->setRelation('client', $this->barnes);
    $attendee->syncMembers($this->members->mapWithKeys(fn ($m) => [
        $m->id => ['attended' => in_array($m->name, ['Mom', 'Dad'], true), 'price_override' => null],
    ])->all());

    app(CompleteTrainingSession::class)->handle($session->fresh());

    expect($this->barnes->balance())->toBe(-94.50); // 2 × the $45 partner rate + GST
});

it('quotes on screen exactly what it charges', function () {
    $component = Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-09-01')
        ->set('time', '09:00')
        ->call('addClient', $this->barnes->id)
        ->set('attendees.'.$this->barnes->id.'.members.'.$this->members['Mom']->id.'.attended', true)
        ->set('attendees.'.$this->barnes->id.'.members.'.$this->members['Dad']->id.'.attended', true)
        ->set('attendees.'.$this->barnes->id.'.members.'.$this->members['Ellie']->id.'.attended', true);

    $quoted = $component->viewData('preview')['total'];

    $component->call('save', true)->assertHasNoErrors();

    $charged = abs((float) WalletTransaction::query()->where('client_id', $this->barnes->id)->sum('amount'));

    expect($quoted)->toBe(78.75)->and($charged)->toBe($quoted);
});

it('needs at least one member ticked before it will log the session', function () {
    Livewire::test(Log::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->barnes->id)
        ->call('save', true)
        ->assertHasErrors('attendees');

    expect(TrainingSession::count())->toBe(0);
});

it('carries the member selection through a repeat and a bulk batch', function () {
    $selection = [$this->members['Mom']->id => ['attended' => true, 'price_override' => null]];

    $series = app(BookSessionSeries::class)->handle([
        'user_id' => $this->trainer->id,
        'service_id' => $this->service->id,
        'gym_id' => null,
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-15',
        'time' => '09:00',
        'duration_minutes' => 60,
        'interval_weeks' => 1,
        'weekdays' => [2],
        'notes' => null,
    ], [$this->barnes->id => ['price_override' => null, 'members' => $selection]], false);

    expect($series->sessions()->count())->toBe(3);
    $series->sessions->each(fn ($s) => expect($s->headcount())->toBe(1));

    $sessions = app(LogSessionsInBulk::class)->handle(
        ['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'gym_id' => null, 'time' => '07:00', 'duration_minutes' => 60, 'notes' => null],
        ['2026-10-01', '2026-10-08'],
        [$this->barnes->id => ['attended' => true, 'price_override' => null, 'members' => $selection]],
    );

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]->headcount())->toBe(1)
        ->and($this->barnes->balance())->toBe(-126.00); // 2 × the $60 single rate + GST
});

it('bills the gym for the whole family, not for one client record', function () {
    $gym = Gym::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Westside']);
    $session = familySession($this, ['Mom', 'Dad', 'Ellie'], '2026-09-02 09:00:00');
    $session->update(['gym_id' => $gym->id]);
    app(CompleteTrainingSession::class)->handle($session->fresh());

    $report = app(GymUsageReportBuilder::class)->build($gym->fresh(), 2026, 9);

    expect($report['rows'][0]['people'])->toBe(3)
        ->and($report['rows'][0]['rate'])->toBe(35.0)   // the gym's 3-person rate, not its 1-person rate
        ->and($report['rows'][0]['attendees'])->toBe(['Mom', 'Dad', 'Ellie'])
        ->and($report['summary']['people'])->toBe(3);
});

it('counts family members as people in the monthly report', function () {
    $session = familySession($this, ['Mom', 'Dad', 'Ellie'], '2026-09-03 09:00:00');
    app(CompleteTrainingSession::class)->handle($session);

    $report = app(ReportBuilder::class)->monthly($this->trainer, 2026, 9);

    expect($report['sessions']['attendances'])->toBe(3)
        ->and($report['sessions']['by_service']['Personal Training']['tiers'])->toBe(['Triple' => 1]);
});

it('finds a family by a member name and keeps members to the cap', function () {
    expect(Client::query()->search('Ellie')->pluck('id')->all())->toBe([$this->barnes->id])
        ->and(Client::MAX_MEMBERS)->toBe(10);
});

it('hides family members from other trainers', function () {
    $other = User::factory()->create();
    $this->actingAs($other);

    expect(FamilyMember::query()->count())->toBe(0)
        ->and(Client::query()->search('Ellie')->count())->toBe(0);
});

it('adds, renames and retires members from the client form', function () {
    Livewire::test(ClientForm::class)
        ->set('plan_id', (string) $this->familyPlan->id)
        ->set('first_name', 'Okonkwo')
        ->set('last_name', 'Family')
        ->set('members', [['id' => null, 'name' => 'Ada'], ['id' => null, 'name' => 'Chidi'], ['id' => null, 'name' => '']])
        ->call('save')
        ->assertHasNoErrors();

    $okonkwo = Client::query()->where('first_name', 'Okonkwo')->first();
    expect($okonkwo->members->pluck('name')->all())->toBe(['Ada', 'Chidi']);

    $ada = $okonkwo->members->firstWhere('name', 'Ada');

    Livewire::test(ClientForm::class, ['client' => $okonkwo])
        ->set('members', [['id' => $ada->id, 'name' => 'Ada N.']])
        ->call('save')
        ->assertHasNoErrors();

    expect($ada->fresh()->name)->toBe('Ada N.')                        // renamed in place, same member
        ->and($okonkwo->members()->count())->toBe(1);                   // Chidi never trained, so he is gone
});

it('keeps a member who has trained instead of deleting them', function () {
    $session = familySession($this, ['Mom', 'Dad']);
    app(CompleteTrainingSession::class)->handle($session);

    Livewire::test(ClientForm::class, ['client' => $this->barnes])
        ->set('members', [['id' => $this->members['Mom']->id, 'name' => 'Mom (Sarah)']])
        ->call('save')
        ->assertHasNoErrors();

    expect($this->members['Dad']->fresh()->active)->toBeFalse()          // retired, not erased
        ->and($this->barnes->activeMembers()->count())->toBe(1)
        ->and($session->fresh()->headcount())->toBe(2)                   // history is untouched
        ->and($session->fresh()->attendees->first()->members->where('attended', true))->toHaveCount(2)
        ->and($session->fresh()->attendees->first()->peopleNames())->toBe(['Mom', 'Dad']); // still named on the session
});

it('will not take more members than the cap allows', function () {
    $eleven = collect(range(1, 11))->map(fn ($i) => ['id' => null, 'name' => "Person {$i}"])->all();

    Livewire::test(ClientForm::class, ['client' => $this->barnes])
        ->set('members', $eleven)
        ->call('save')
        ->assertHasErrors('members');
});
