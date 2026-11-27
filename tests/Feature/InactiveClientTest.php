<?php

use App\Actions\CompleteTrainingSession;
use App\Actions\CreateInvoice;
use App\Actions\PostAdjustment;
use App\Actions\PostMonthlyFee;
use App\Actions\RecordPayment;
use App\Enums\ClientStatus;
use App\Enums\PaymentMethod;
use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Livewire\Clients\Show;
use App\Livewire\Sessions\Log;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->trainer = User::factory()->create([
        'gst_rate' => 5,
        'gst_registered' => true,
        'payment_methods' => ['cash', 'etransfer'],
        'etransfer_email' => 'pay@brooke.example',
    ]);
    $this->actingAs($this->trainer);

    $this->service = Service::factory()->create(['user_id' => $this->trainer->id]);
    $this->wallet = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $this->wallet->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);

    $this->client = Client::factory()->create([
        'user_id' => $this->trainer->id,
        'plan_id' => $this->wallet->id,
        'email' => 'ava@example.com',
        'first_name' => 'Ava',
        'last_name' => 'Stone',
    ]);
});

function deactivate(Client $client): Client
{
    $client->forceFill(['status' => ClientStatus::Inactive])->save();

    return $client->fresh();
}

it('refuses a payment for an inactive client', function () {
    $client = deactivate($this->client);

    expect(fn () => app(RecordPayment::class)->handle($client, 100, PaymentMethod::Cash))
        ->toThrow(BillingException::class, 'Ava Stone is inactive. Reactivate them before recording a payment.');

    expect(WalletTransaction::where('client_id', $client->id)->count())->toBe(0);
});

it('refuses an adjustment or refund for an inactive client', function () {
    $client = deactivate($this->client);

    expect(fn () => app(PostAdjustment::class)->handle($client, 10, 'Goodwill'))
        ->toThrow(BillingException::class, 'Reactivate them before posting an adjustment.');

    expect(fn () => app(PostAdjustment::class)->handle($client, 10, 'Refund', TransactionType::Refund, null, PaymentMethod::Cash))
        ->toThrow(BillingException::class, 'Reactivate them before posting a refund.');

    expect(WalletTransaction::where('client_id', $client->id)->count())->toBe(0);
});

it('refuses a payment request for an inactive client', function () {
    $client = deactivate($this->client);

    expect(fn () => app(CreateInvoice::class)->handle($client, 100))
        ->toThrow(BillingException::class, 'Reactivate them before requesting a payment.');

    expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
});

it('refuses a monthly fee for an inactive monthly client', function () {
    $monthly = Plan::factory()->monthly()->create(['user_id' => $this->trainer->id]);
    $this->client->update(['plan_id' => $monthly->id]);
    $client = deactivate($this->client);

    app(PostMonthlyFee::class)->handle($client, '2026-11-01');
})->throws(BillingException::class, 'Reactivate them before posting a monthly fee.');

it('shows the errors on the client page and posts nothing', function () {
    deactivate($this->client);

    Livewire::test(Show::class, ['client' => $this->client->fresh()])
        ->set('paymentAmount', '100')
        ->set('paymentMethod', 'cash')
        ->call('recordPayment')
        ->assertHasErrors('paymentAmount')
        ->set('adjustmentAmount', '10')
        ->set('adjustmentNote', 'Goodwill')
        ->call('postAdjustment')
        ->assertHasErrors('adjustmentAmount')
        ->set('requestAmount', '100')
        ->call('requestPayment')
        ->assertHasErrors('requestAmount');

    expect(WalletTransaction::where('client_id', $this->client->id)->count())->toBe(0)
        ->and(Invoice::where('client_id', $this->client->id)->count())->toBe(0);
});

it('refuses to mark an existing request paid once the client is inactive', function () {
    $invoice = app(CreateInvoice::class)->handle($this->client, 100);
    deactivate($this->client);

    Livewire::test(Show::class, ['client' => $this->client->fresh()])
        ->call('openMarkPaid', $invoice->id)
        ->assertSet('markPaidId', null)
        ->set('markPaidId', $invoice->id)
        ->set('markPaidAmount', '105')
        ->set('markPaidMethod', 'cash')
        ->set('markPaidDate', today()->toDateString())
        ->call('markPaid')
        ->assertHasErrors('markPaidAmount');

    expect(WalletTransaction::where('client_id', $this->client->id)->count())->toBe(0)
        ->and($invoice->fresh()->isOutstanding())->toBeTrue();
});

it('still lets the trainer void and edit what is already on the record', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 100, PaymentMethod::Cash);
    deactivate($this->client);

    Livewire::test(Show::class, ['client' => $this->client->fresh()])
        ->call('editTransaction', $tx->id)
        ->set('editAmount', '120')
        ->set('editReason', 'Typo')
        ->call('updateTransaction')
        ->assertHasNoErrors()
        ->call('voidTransaction', $tx->id);

    expect($tx->fresh()->voided_at)->not->toBeNull();
});

it('hides the action buttons and explains why on an inactive client page', function () {
    $invoice = app(CreateInvoice::class)->handle($this->client, 100);
    deactivate($this->client);

    Livewire::test(Show::class, ['client' => $this->client->fresh()])
        ->assertSee('This client is inactive')
        ->assertDontSee('Request payment')
        ->assertDontSee('Log session')
        ->assertDontSeeHtml('wire:submit="recordPayment"')
        ->assertDontSeeHtml('wire:submit="postAdjustment"')
        ->assertDontSeeHtml('openMarkPaid(')
        ->assertSee('Remind');
});

it('shows the action buttons again after reactivation', function () {
    deactivate($this->client);
    $this->client->forceFill(['status' => ClientStatus::Active])->save();

    Livewire::test(Show::class, ['client' => $this->client->fresh()])
        ->assertDontSee('This client is inactive')
        ->assertSee('Log session')
        ->assertSeeHtml('wire:submit="recordPayment"');

    $tx = app(RecordPayment::class)->handle($this->client->fresh(), 100, PaymentMethod::Cash);

    expect((float) $tx->amount)->toBe(100.00);
});

it('blocks completing a session that an inactive client is booked on', function () {
    $session = TrainingSession::factory()->create([
        'user_id' => $this->trainer->id,
        'service_id' => $this->service->id,
        'starts_at' => '2026-11-20 09:00:00',
    ]);
    SessionAttendee::factory()->create(['training_session_id' => $session->id, 'client_id' => $this->client->id]);
    deactivate($this->client);

    expect(fn () => app(CompleteTrainingSession::class)->handle($session->fresh()))
        ->toThrow(BillingException::class, 'Ava Stone is inactive.');

    expect($session->fresh()->status)->toBe(SessionStatus::Scheduled)
        ->and(WalletTransaction::where('client_id', $this->client->id)->count())->toBe(0);
});

it('will not add an inactive client to a new session', function () {
    deactivate($this->client);

    Livewire::test(Log::class)
        ->call('addClient', $this->client->id)
        ->assertSet('attendees', []);
});

it('will not book a session if an inactive client is smuggled into the attendees', function () {
    deactivate($this->client);

    Livewire::test(Log::class, ['mode' => 'book'])
        ->set('service_id', (string) $this->service->id)
        ->set('date', '2026-12-10')
        ->set('time', '09:00')
        ->set('attendees', [$this->client->id => ['attendance' => 'attended', 'override' => '', 'client_note' => '', 'members' => []]])
        ->call('save', false)
        ->assertHasErrors('attendees');

    expect(TrainingSession::count())->toBe(0);
});
