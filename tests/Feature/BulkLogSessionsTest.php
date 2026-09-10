<?php

use App\Actions\LogSessionsInBulk;
use App\Actions\RecordPayment;
use App\Enums\PaymentMethod;
use App\Enums\SessionStatus;
use App\Exceptions\BillingException;
use App\Livewire\Sessions\BulkLog;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\DateList;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'payment_methods' => ['cash']]);
    $this->actingAs($this->trainer);

    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $plan->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 40]);

    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'first_name' => 'Ava']);
    $this->ben = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'first_name' => 'Ben']);
});

it('logs one completed session per date and charges every attendee', function () {
    app(RecordPayment::class)->handle($this->ava, 500, PaymentMethod::Cash);

    $sessions = app(LogSessionsInBulk::class)->handle(
        ['service_id' => $this->service->id, 'gym_id' => null, 'time' => '07:30', 'duration_minutes' => 60, 'notes' => 'Imported'],
        ['2026-06-05', '2026-06-03', '2026-06-03'],
        [$this->ava->id => ['attended' => true, 'price_override' => null]],
    );

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]->starts_at->format('Y-m-d H:i'))->toBe('2026-06-03 07:30')
        ->and($sessions[1]->starts_at->format('Y-m-d H:i'))->toBe('2026-06-05 07:30')
        ->and($sessions[0]->status)->toBe(SessionStatus::Completed)
        ->and($sessions[0]->notes)->toBe('Imported')
        ->and($this->ava->balance())->toBe(500.00 - 2 * 63.00); // 2 × ($60 + $3 GST)
});

it('falls back to a default time when none is given', function () {
    $sessions = app(LogSessionsInBulk::class)->handle(
        ['service_id' => $this->service->id, 'gym_id' => null, 'time' => '', 'duration_minutes' => 60, 'notes' => null],
        ['2026-06-03'],
        [$this->ava->id => []],
    );

    expect($sessions[0]->starts_at->format('H:i'))->toBe(LogSessionsInBulk::DEFAULT_TIME);
});

it('prices each session by how many people attended it', function () {
    $sessions = app(LogSessionsInBulk::class)->handle(
        ['service_id' => $this->service->id, 'gym_id' => null, 'time' => '09:00', 'duration_minutes' => 60, 'notes' => null],
        ['2026-06-03'],
        [$this->ava->id => [], $this->ben->id => []],
    );

    expect((float) $sessions[0]->attendees->first()->subtotal)->toBe(40.00);
});

it('refuses more than the batch limit, and empty dates or clients', function () {
    $tooMany = collect(range(0, LogSessionsInBulk::MAX_SESSIONS))->map(fn ($i) => now()->addDays($i)->toDateString())->all();
    $setup = ['service_id' => $this->service->id, 'gym_id' => null, 'time' => '09:00', 'duration_minutes' => 60, 'notes' => null];

    expect(fn () => app(LogSessionsInBulk::class)->handle($setup, $tooMany, [$this->ava->id => []]))
        ->toThrow(BillingException::class)
        ->and(fn () => app(LogSessionsInBulk::class)->handle($setup, [], [$this->ava->id => []]))
        ->toThrow(BillingException::class)
        ->and(fn () => app(LogSessionsInBulk::class)->handle($setup, ['2026-06-03'], []))
        ->toThrow(BillingException::class)
        ->and(fn () => app(LogSessionsInBulk::class)->handle($setup, ['not a date'], [$this->ava->id => []]))
        ->toThrow(BillingException::class);

    expect(TrainingSession::count())->toBe(0);
});

it('leaves nothing behind when one session in the batch fails', function () {
    $noPlan = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => null]);

    expect(fn () => app(LogSessionsInBulk::class)->handle(
        ['service_id' => $this->service->id, 'gym_id' => null, 'time' => '09:00', 'duration_minutes' => 60, 'notes' => null],
        ['2026-06-03', '2026-06-05'],
        [$noPlan->id => []],
    ))->toThrow(BillingException::class);

    expect(TrainingSession::count())->toBe(0);
});

it('picks dates on the calendar and logs the batch from the screen', function () {
    Livewire::test(BulkLog::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->ava->id)
        ->call('toggleDate', '2026-06-03')
        ->call('toggleDate', '2026-06-05')
        ->call('toggleDate', '2026-06-10')
        ->call('toggleDate', '2026-06-05') // clicking again drops it
        ->assertSet('dates', ['2026-06-03', '2026-06-10'])
        ->assertSee('2 of '.LogSessionsInBulk::MAX_SESSIONS)
        ->call('save', true)
        ->assertHasNoErrors()
        ->assertRedirect(route('sessions.calendar', ['view' => 'month', 'date' => '2026-06-03']));

    expect(TrainingSession::count())->toBe(2)
        ->and(TrainingSession::query()->where('status', SessionStatus::Completed)->count())->toBe(2)
        ->and($this->ava->balance())->toBe(-126.00);
});

it('adds pasted dates and reports what it could not read', function () {
    Livewire::test(BulkLog::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->ava->id)
        ->set('paste', "2026-06-03\nJun 5, 2026, 06/10/2026\nsometime in July")
        ->call('addPastedDates')
        ->assertHasNoErrors()
        ->assertSet('dates', ['2026-06-03', '2026-06-05', '2026-06-10'])
        ->assertSet('paste', '');
});

it('rejects a paste with no readable dates', function () {
    Livewire::test(BulkLog::class)
        ->set('paste', 'last tuesday and the tuesday before')
        ->call('addPastedDates')
        ->assertHasErrors('paste')
        ->assertSet('dates', []);
});

it('stops the selection at the batch limit', function () {
    $component = Livewire::test(BulkLog::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->ava->id)
        ->set('paste', collect(range(1, 60))->map(fn ($i) => now()->addDays($i)->toDateString())->join(', '))
        ->call('addPastedDates');

    expect($component->get('dates'))->toHaveCount(LogSessionsInBulk::MAX_SESSIONS);
});

it('needs a date and a client before it will log anything', function () {
    Livewire::test(BulkLog::class)
        ->set('service_id', (string) $this->service->id)
        ->call('save', true)
        ->assertHasErrors(['dates', 'attendees']);

    expect(TrainingSession::count())->toBe(0);
});

it('warns when a client already has a session on a picked date', function () {
    app(LogSessionsInBulk::class)->handle(
        ['service_id' => $this->service->id, 'gym_id' => null, 'time' => '09:00', 'duration_minutes' => 60, 'notes' => null],
        ['2026-06-03'],
        [$this->ava->id => []],
    );

    Livewire::test(BulkLog::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->ava->id)
        ->call('toggleDate', '2026-06-03')
        ->assertSee('already');
});

it('can save the batch as scheduled instead of charging it', function () {
    Livewire::test(BulkLog::class)
        ->set('service_id', (string) $this->service->id)
        ->call('addClient', $this->ava->id)
        ->call('toggleDate', '2026-06-03')
        ->call('save', false)
        ->assertHasNoErrors();

    expect(TrainingSession::query()->where('status', SessionStatus::Scheduled)->count())->toBe(1)
        ->and($this->ava->balance())->toBe(0.0);
});

it('reads the date formats a spreadsheet is likely to hand over', function () {
    $parsed = DateList::parse("2026-06-03\n06/10/2026\nJun 5, 2026\n3 July 2026\nnope");

    expect($parsed['dates'])->toBe(['2026-06-03', '2026-06-10', '2026-06-05', '2026-07-03'])
        ->and($parsed['ignored'])->toBe(['nope']);
});
