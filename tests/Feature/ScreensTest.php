<?php

use App\Enums\PaymentMethod;
use App\Livewire\Clients;
use App\Livewire\Plans;
use App\Livewire\Services;
use App\Livewire\Sessions;
use App\Livewire\Settings\Business;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'payment_methods' => ['cash', 'etransfer']]);
    $this->actingAs($this->trainer);
});

it('renders every main page for an authenticated trainer', function () {
    $service = Service::factory()->create(['user_id' => $this->trainer->id]);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);
    $session = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $service->id]);

    foreach ([
        route('dashboard'),
        route('clients.index'),
        route('clients.create'),
        route('clients.show', $client),
        route('clients.edit', $client),
        route('services.index'),
        route('plans.index'),
        route('plans.create'),
        route('plans.edit', $plan),
        route('sessions.index'),
        route('sessions.log'),
        route('sessions.show', $session),
        route('settings.business'),
        route('reports.monthly'),
        route('reports.annual'),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

it('returns 404 for another trainer\'s records', function () {
    $other = User::factory()->create();
    $client = Client::factory()->create(['user_id' => $other->id]);
    $plan = Plan::factory()->create(['user_id' => $other->id]);
    $session = TrainingSession::factory()->create(['user_id' => $other->id]);

    $this->get(route('clients.show', $client))->assertNotFound();
    $this->get(route('clients.edit', $client))->assertNotFound();
    $this->get(route('plans.edit', $plan))->assertNotFound();
    $this->get(route('sessions.show', $session))->assertNotFound();
});

it('redirects guests and sends logged-in users from the landing page to the dashboard', function () {
    auth()->logout();
    $this->get(route('home'))->assertOk()->assertSee('BrookeApp');
    $this->get(route('clients.index'))->assertRedirect(route('login'));

    $this->actingAs($this->trainer)->get(route('home'))->assertRedirect(route('dashboard'));
});

it('creates and edits a client', function () {
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);

    Livewire::test(Clients\Form::class)
        ->set('first_name', 'Sam')
        ->set('last_name', 'Lee')
        ->set('email', 'sam@example.com')
        ->set('plan_id', (string) $plan->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $client = Client::first();
    expect($client->full_name)->toBe('Sam Lee')->and($client->plan_id)->toBe($plan->id);

    Livewire::test(Clients\Form::class, ['client' => $client])
        ->set('first_name', 'Samantha')
        ->call('save')
        ->assertHasNoErrors();

    expect($client->fresh()->first_name)->toBe('Samantha');

    // Duplicate email for the same trainer is rejected.
    Livewire::test(Clients\Form::class)
        ->set('first_name', 'Dup')
        ->set('email', 'sam@example.com')
        ->call('save')
        ->assertHasErrors(['email']);
});

it('records a payment, an adjustment and a monthly fee from the client page', function () {
    $monthly = Plan::factory()->monthly(300, 1)->create(['user_id' => $this->trainer->id]);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $monthly->id]);

    Livewire::test(Clients\Show::class, ['client' => $client])
        ->call('postMonthlyFee')
        ->set('paymentAmount', '315')
        ->set('paymentMethod', 'etransfer')
        ->set('paymentDate', '2026-09-02')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->set('adjustmentKind', 'credit')
        ->set('adjustmentAmount', '10')
        ->set('adjustmentNote', 'Referral bonus')
        ->call('postAdjustment')
        ->assertHasNoErrors();

    expect($client->balance())->toBe(10.00)
        ->and(WalletTransaction::count())->toBe(3);

    // A disabled method is rejected with a form error.
    Livewire::test(Clients\Show::class, ['client' => $client])
        ->set('paymentAmount', '50')
        ->set('paymentMethod', PaymentMethod::Cheque->value)
        ->call('recordPayment')
        ->assertHasErrors(['paymentAmount']);

    // Void the adjustment.
    $adjustment = WalletTransaction::where('type', 'adjustment')->first();
    Livewire::test(Clients\Show::class, ['client' => $client])
        ->call('voidTransaction', $adjustment->id);

    expect($client->balance())->toBe(0.0);
});

it('manages services and plans with rate grids', function () {
    Livewire::test(Services\Index::class)
        ->call('create')
        ->set('name', 'Personal Training')
        ->set('duration_minutes', '60')
        ->call('save')
        ->assertHasNoErrors();

    $service = Service::first();
    expect($service->name)->toBe('Personal Training');

    Livewire::test(Plans\Form::class)
        ->set('name', 'Standard')
        ->set('type', 'wallet')
        ->set("rates.{$service->id}.1", '60')
        ->set("rates.{$service->id}.2", '30')
        ->call('save')
        ->assertHasNoErrors();

    $plan = Plan::first();
    expect($plan->rates)->toHaveCount(2)
        ->and((float) $plan->rateFor($service, 2)->unit_price)->toBe(30.00);

    // Switching to monthly clears the rates.
    Livewire::test(Plans\Form::class, ['plan' => $plan])
        ->set('type', 'monthly')
        ->set('monthly_fee', '250')
        ->set('billing_day', '15')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->rates)->toHaveCount(0)
        ->and((float) $plan->fresh()->monthly_fee)->toBe(250.00);

    // Monthly plan requires a fee.
    Livewire::test(Plans\Form::class)
        ->set('name', 'Broken')
        ->set('type', 'monthly')
        ->set('monthly_fee', '')
        ->call('save')
        ->assertHasErrors(['monthly_fee']);
});

it('logs a partner session and charges both wallets, then reopens it from the session page', function () {
    $service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'PT']);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $plan->id, 'service_id' => $service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $plan->id, 'service_id' => $service->id, 'headcount' => 2, 'unit_price' => 30]);
    $a = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);
    $b = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    Livewire::test(Sessions\Log::class)
        ->set('service_id', (string) $service->id)
        ->set('date', '2026-09-03')
        ->set('time', '09:00')
        ->call('addClient', $a->id)
        ->call('addClient', $b->id)
        ->assertSee('Partner session')
        ->assertSee('$31.50')
        ->call('save', true)
        ->assertHasNoErrors()
        ->assertRedirect();

    $session = TrainingSession::first();
    expect($session->isCompleted())->toBeTrue()
        ->and($a->balance())->toBe(-31.50)
        ->and($b->balance())->toBe(-31.50);

    Livewire::test(Sessions\Show::class, ['trainingSession' => $session])
        ->call('reopen')
        ->set("attendees.{$b->id}.attended", false)
        ->call('complete')
        ->assertHasNoErrors();

    expect($a->balance())->toBe(-63.00)
        ->and($b->balance())->toBe(0.0);
});

it('shows a clear error when a rate is missing instead of charging', function () {
    $service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Nutrition']);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id, 'name' => 'Standard']);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    Livewire::test(Sessions\Log::class)
        ->set('service_id', (string) $service->id)
        ->call('addClient', $client->id)
        ->assertSee('No Single rate is set for Nutrition')
        ->call('save', true)
        ->assertHasErrors(['attendees']);

    expect(TrainingSession::count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0);
});

it('saves business settings', function () {
    Livewire::test(Business::class)
        ->set('business_name', 'Brooke Fitness')
        ->set('gst_number', '123456789 RT0001')
        ->set('payment_methods', ['cash'])
        ->call('save')
        ->assertHasNoErrors();

    $trainer = $this->trainer->fresh();
    expect($trainer->business_name)->toBe('Brooke Fitness')
        ->and($trainer->gst_number)->toBe('123456789 RT0001')
        ->and($trainer->enabledPaymentMethods())->toBe([PaymentMethod::Cash]);

    Livewire::test(Business::class)
        ->set('payment_methods', [])
        ->call('save')
        ->assertHasErrors(['payment_methods']);
});
