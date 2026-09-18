<?php

use App\Actions\CompleteTrainingSession;
use App\Actions\ReopenTrainingSession;
use App\Enums\Attendance;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\Log;
use App\Livewire\Sessions\Show;
use App\Mail\SessionCompletedMail;
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
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'gst_registered' => true]);
    $this->actingAs($this->trainer);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training', 'duration_minutes' => 60]);
    $this->plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 30]);
    $this->gym = Gym::factory()->noGst()->create(['user_id' => $this->trainer->id, 'name' => 'Westside', 'is_default' => true]);
    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id, 'first_name' => 'Ava', 'last_name' => 'Nguyen', 'email' => 'ava@example.com']);
    $this->ben = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id, 'first_name' => 'Ben', 'last_name' => 'Ortiz']);
});

function lateCancelSession($test, array $attendees): TrainingSession
{
    $session = TrainingSession::factory()->create([
        'user_id' => $test->trainer->id,
        'service_id' => $test->service->id,
        'gym_id' => $test->gym->id,
        'starts_at' => '2026-09-08 09:00:00',
        'duration_minutes' => 60,
    ]);

    foreach ($attendees as $clientId => $state) {
        SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $clientId, ...$state]);
    }

    return $session->refresh();
}

function logForm($test)
{
    return Livewire::test(Log::class)
        ->set('service_id', (string) $test->service->id)
        ->set('gym_id', (string) $test->gym->id)
        ->set('date', '2026-09-08')
        ->set('time', '09:00')
        ->set('duration_minutes', '60');
}

function gymMonth($test): array
{
    return app(GymUsageReportBuilder::class)->build($test->gym, 2026, 9);
}

it('charges a late cancel as if they came, explains it on the ledger, and keeps the gym out of it', function () {
    $session = lateCancelSession($this, [$this->ava->id => ['late_cancelled' => true, 'client_note' => 'Cancelled 2 hours before — 24-hour policy']]);

    app(CompleteTrainingSession::class)->handle($session);

    $tx = WalletTransaction::query()->where('client_id', $this->ava->id)->sole();
    expect($this->ava->balance())->toBe(-63.0)
        ->and($tx->description)->toBe('Personal Training (Single) — late cancel: Cancelled 2 hours before — 24-hour policy')
        ->and($session->fresh()->roomHeadcount())->toBe(0)
        ->and($session->fresh()->headcount())->toBe(1);

    $report = gymMonth($this);
    expect($report['rows'])->toBe([])
        ->and($report['summary']['sessions'])->toBe(0)
        ->and($report['summary']['usage_subtotal'])->toBe(0.0);
});

it('prices a partner late cancel at the booked tier while the gym bills only who was there', function () {
    $session = lateCancelSession($this, [
        $this->ava->id => ['late_cancelled' => true, 'client_note' => 'Sick'],
        $this->ben->id => [],
    ]);

    app(CompleteTrainingSession::class)->handle($session);

    // Partner rate for both: Ben is not pushed up to the single rate because Ava bailed.
    expect($this->ava->balance())->toBe(-31.5)
        ->and($this->ben->balance())->toBe(-31.5);

    $report = gymMonth($this);
    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]['people'])->toBe(1)
        ->and($report['rows'][0]['amount'])->toBe(18.0)
        ->and($report['rows'][0]['attendees'])->toBe(['Ben Ortiz']);
});

it('refuses a late cancel without a reason and charges nobody', function () {
    $session = lateCancelSession($this, [$this->ava->id => ['late_cancelled' => true, 'client_note' => '  ']]);

    expect(fn () => app(CompleteTrainingSession::class)->handle($session))
        ->toThrow(BillingException::class, "Add a reason for Ava Nguyen's late cancel");

    expect(WalletTransaction::count())->toBe(0)
        ->and($session->fresh()->isScheduled())->toBeTrue();
});

it('adds an optional note to an ordinary charge', function () {
    $session = lateCancelSession($this, [$this->ava->id => ['client_note' => 'Includes the extra 15 minutes']]);

    app(CompleteTrainingSession::class)->handle($session);

    expect(WalletTransaction::query()->sole()->description)->toBe('Personal Training (Single) — Includes the extra 15 minutes');
});

it('never charges the gym for an empty room', function () {
    lateCancelSession($this, [$this->ava->id => ['attended' => false]])->update(['status' => 'completed']);

    expect(gymMonth($this)['rows'])->toBe([]);
});

it('reads a stray late-cancel flag on someone who is not on the bill as a no-show', function () {
    expect(Attendance::fromFlags(false, true))->toBe(Attendance::NoShow)
        ->and(Attendance::fromFlags(true, true))->toBe(Attendance::LateCancel)
        ->and(Attendance::fromFlags(true, false))->toBe(Attendance::Attended)
        ->and(Attendance::fromInput('nonsense'))->toBe(Attendance::Attended);
});

it('keeps the late cancel and reason through a reopen, voiding only the money', function () {
    $session = lateCancelSession($this, [$this->ava->id => ['late_cancelled' => true, 'client_note' => 'Sick']]);
    app(CompleteTrainingSession::class)->handle($session);

    app(ReopenTrainingSession::class)->handle($session->fresh());

    $attendee = $session->attendees()->sole();
    expect($attendee->isLateCancel())->toBeTrue()
        ->and($attendee->client_note)->toBe('Sick')
        ->and($this->ava->balance())->toBe(0.0);
});

describe('families', function () {
    beforeEach(function () {
        $family = Plan::factory()->family()->create(['user_id' => $this->trainer->id]);
        foreach ([1 => 60, 2 => 45] as $headcount => $price) {
            PlanRate::factory()->create(['plan_id' => $family->id, 'service_id' => $this->service->id, 'headcount' => $headcount, 'unit_price' => $price]);
        }
        $this->barnes = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $family->id, 'first_name' => 'Barnes', 'last_name' => 'Family']);
        $this->members = collect(['Mom', 'Dad'])->map(fn ($name, $i) => FamilyMember::factory()->create([
            'user_id' => $this->trainer->id, 'client_id' => $this->barnes->id, 'name' => $name, 'sort_order' => $i,
        ]))->keyBy('name');
    });

    it('charges the booked members of a family late cancel, with nobody in the room', function () {
        $session = lateCancelSession($this, [$this->barnes->id => ['late_cancelled' => true, 'client_note' => 'Away for the weekend']]);
        $attendee = $session->attendees()->sole();
        $attendee->syncMembers($this->members->mapWithKeys(fn ($m) => [$m->id => ['attended' => true]])->all());

        app(CompleteTrainingSession::class)->handle($session->fresh());

        expect($this->barnes->balance())->toBe(-94.5) // 2 × $45 + 5% GST
            ->and($session->fresh()->roomHeadcount())->toBe(0)
            ->and(gymMonth($this)['rows'])->toBe([]);
    });

    it('refuses a family late cancel with nobody ticked instead of letting it through free', function () {
        $session = lateCancelSession($this, [$this->barnes->id => ['late_cancelled' => true, 'client_note' => 'Away']]);
        $session->attendees()->sole()->syncMembers($this->members->mapWithKeys(fn ($m) => [$m->id => ['attended' => false]])->all());

        expect(fn () => app(CompleteTrainingSession::class)->handle($session->fresh()))
            ->toThrow(BillingException::class, 'Tick which Barnes Family members were booked');
    });

    it('remembers a family late cancel across a save and reload on the session page', function () {
        $session = lateCancelSession($this, []);

        Livewire::test(Show::class, ['trainingSession' => $session])
            ->call('addClient', $this->barnes->id)
            ->set("attendees.{$this->barnes->id}.attendance", 'late_cancel')
            ->set("attendees.{$this->barnes->id}.client_note", 'Away')
            ->call('saveAttendance')
            ->assertHasNoErrors();

        $reloaded = Livewire::test(Show::class, ['trainingSession' => $session->fresh()]);

        expect($reloaded->get("attendees.{$this->barnes->id}.attendance"))->toBe('late_cancel')
            ->and($reloaded->get("attendees.{$this->barnes->id}.client_note"))->toBe('Away');
    });
});

describe('the log form', function () {
    it('logs a late cancel with its reason and leaves the room empty', function () {
        logForm($this)
            ->call('addClient', $this->ava->id)
            ->set("attendees.{$this->ava->id}.attendance", 'late_cancel')
            ->set("attendees.{$this->ava->id}.client_note", 'No-show, no message')
            ->assertSee('Nobody in the room, so Westside')
            ->call('save')
            ->assertHasNoErrors();

        $attendee = SessionAttendee::query()->sole();
        expect($attendee->attended)->toBeTrue()
            ->and($attendee->late_cancelled)->toBeTrue()
            ->and($attendee->client_note)->toBe('No-show, no message')
            ->and($this->ava->balance())->toBe(-63.0);
    });

    it('asks for the reason before charging a late cancel', function () {
        logForm($this)
            ->call('addClient', $this->ava->id)
            ->set("attendees.{$this->ava->id}.attendance", 'late_cancel')
            ->call('save')
            ->assertHasErrors('attendees');

        expect(TrainingSession::count())->toBe(0);
    });

    it('previews the gym charge for the people in the room only', function () {
        $form = logForm($this)
            ->call('addClient', $this->ava->id)
            ->call('addClient', $this->ben->id)
            ->set("attendees.{$this->ava->id}.attendance", 'late_cancel');

        expect($form->viewData('gymCharge')['people'])->toBe(1)
            ->and($form->viewData('gymCharge')['amount'])->toBe(18.0);
    });

    it('can keep the session off the gym report', function () {
        logForm($this)
            ->call('addClient', $this->ava->id)
            ->set('gymBillable', false)
            ->call('save')
            ->assertHasNoErrors();

        expect(TrainingSession::query()->sole()->gym_billable)->toBeFalse()
            ->and(gymMonth($this)['summary']['sessions'])->toBe(0)
            ->and(gymMonth($this)['summary']['sessions_excluded'])->toBe(1);
    });

    it('resets the gym switch when the gym changes, so a hidden untick cannot ride along', function () {
        $other = Gym::factory()->noGst()->create(['user_id' => $this->trainer->id, 'name' => 'Eastside']);

        $form = logForm($this)->set('gymBillable', false)->set('gym_id', (string) $other->id);

        expect($form->get('gymBillable'))->toBeTrue();
    });
});

describe('what the client sees', function () {
    beforeEach(function () {
        $this->session = lateCancelSession($this, [$this->ava->id => ['late_cancelled' => true, 'client_note' => 'Cancelled 2 hours before']]);
        app(CompleteTrainingSession::class)->handle($this->session);
        $this->attendee = $this->session->attendees()->sole();
    });

    it('sends a late-cancellation receipt carrying the reason', function () {
        $mail = new SessionCompletedMail($this->attendee);

        expect($mail->envelope()->subject)->toBe('Late cancellation — Personal Training')
            ->and($mail->render())
            ->toContain('Missed / late-cancelled session')
            ->toContain('Cancelled 2 hours before')
            ->toContain('Late cancellation (Single rate)')
            ->not->toContain('Thanks for training today');
    });

    it('shows the late cancel and reason on the wallet page', function () {
        $this->get($this->ava->portalUrl())
            ->assertOk()
            ->assertSee('Missed / late cancel')
            ->assertSee('Cancelled 2 hours before');
    });
});
