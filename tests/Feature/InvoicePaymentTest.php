<?php

use App\Actions\CreateInvoice;
use App\Actions\RecordPayment;
use App\Actions\UpdateTransaction;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BillingException;
use App\Livewire\Clients\Show;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create([
        'gst_rate' => 5,
        'gst_registered' => true,
        'payment_methods' => ['cash', 'etransfer'],
        'etransfer_email' => 'pay@brooke.example',
    ]);
    $this->actingAs($this->trainer);

    $service = Service::factory()->create(['user_id' => $this->trainer->id]);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id, 'package_sessions' => 50]);
    $plan->rates()->create(['service_id' => $service->id, 'headcount' => 1, 'unit_price' => 87.50]);

    $this->client = Client::factory()->create([
        'user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'email' => 'erfan@example.com',
    ]);

    // $4,375.00 + $218.75 GST = $4,593.75
    $this->invoice = app(CreateInvoice::class)->handle($this->client, 4375.00, null, today()->addDays(7));
});

it('marks the request paid when the money arrives against it', function () {
    app(RecordPayment::class)->handle($this->client, 4593.75, PaymentMethod::ETransfer, null, null, null, $this->invoice);

    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->fresh()->outstandingAmount())->toBe(0.0)
        ->and(Invoice::outstanding()->count())->toBe(0);
});

it('leaves it partly paid until the rest arrives', function () {
    app(RecordPayment::class)->handle($this->client, 1000, PaymentMethod::Cash, null, null, null, $this->invoice);

    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::PartlyPaid)
        ->and($this->invoice->fresh()->outstandingAmount())->toBe(3593.75);

    app(RecordPayment::class)->handle($this->client, 3593.75, PaymentMethod::Cash, null, null, null, $this->invoice);

    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::Paid);
});

it('marks an overpayment paid and leaves the surplus as wallet credit', function () {
    app(RecordPayment::class)->handle($this->client, 5000, PaymentMethod::ETransfer, null, null, null, $this->invoice);

    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->fresh()->outstandingAmount())->toBe(0.0)
        ->and($this->client->balance())->toBe(5000.00);
});

it('reopens the request when the linked payment is voided', function () {
    $payment = app(RecordPayment::class)->handle($this->client, 4593.75, PaymentMethod::Cash, null, null, null, $this->invoice);
    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::Paid);

    Livewire::test(Show::class, ['client' => $this->client])->call('voidTransaction', $payment->id);

    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::Sent)
        ->and($this->invoice->fresh()->paidAmount())->toBe(0.0)
        ->and(Invoice::outstanding()->count())->toBe(1);
});

it('follows the linked payment when its amount is edited', function () {
    $payment = app(RecordPayment::class)->handle($this->client, 4593.75, PaymentMethod::Cash, null, null, null, $this->invoice);

    app(UpdateTransaction::class)->handle($payment, 1000.00, today(), 'Client only sent part of it', null, PaymentMethod::Cash);
    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::PartlyPaid);

    app(UpdateTransaction::class)->handle($payment->fresh(), 4593.75, today(), 'The rest came through', null, PaymentMethod::Cash);
    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::Paid);
});

it('reads overdue once the due date has passed with nothing paid', function () {
    $overdue = app(CreateInvoice::class)->handle($this->client, 500, today()->subDays(30), today()->subDays(23));

    expect($overdue->status())->toBe(InvoiceStatus::Overdue);

    app(RecordPayment::class)->handle($this->client, 525, PaymentMethod::Cash, null, null, null, $overdue);

    expect($overdue->fresh()->status())->toBe(InvoiceStatus::Paid);
});

it('names the request on the ledger row so the money says what it was for', function () {
    $payment = app(RecordPayment::class)->handle($this->client, 100, PaymentMethod::Cash, null, null, null, $this->invoice);
    $loose = app(RecordPayment::class)->handle($this->client, 100, PaymentMethod::Cash);

    expect($payment->description)->toBe('Fitness Wallet deposit — INV-0001')
        ->and($loose->description)->toBe('Fitness Wallet deposit')
        ->and($loose->invoice_id)->toBeNull();
});

it('refuses to link a payment to another client\'s request', function () {
    $other = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->client->plan_id]);

    app(RecordPayment::class)->handle($other, 100, PaymentMethod::Cash, null, null, null, $this->invoice);
})->throws(BillingException::class);

it('offers the open request on the record payment form and clears it once settled', function () {
    Livewire::test(Show::class, ['client' => $this->client])
        ->assertSet('paymentInvoiceId', (string) $this->invoice->id)
        ->set('paymentAmount', '4593.75')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertSet('paymentInvoiceId', '');

    expect($this->invoice->fresh()->status())->toBe(InvoiceStatus::Paid);
});

it('shows outstanding requests on the client wallet page and drops them once paid', function () {
    $url = $this->client->portalUrl();

    $this->get($url)
        ->assertOk()
        ->assertSee('Payment requested')
        ->assertSee('INV-0001')
        ->assertSee('$4,593.75')
        ->assertSee('pay@brooke.example');

    app(RecordPayment::class)->handle($this->client, 4593.75, PaymentMethod::ETransfer, null, null, null, $this->invoice);

    // The panel goes; the ledger row still names the request, which is the point of it.
    $this->get($url)->assertOk()->assertDontSee('Payment requested')->assertSee('INV-0001');
});

it('hides a voided request from the client', function () {
    $this->invoice->forceFill(['voided_at' => now()])->save();

    $this->get($this->client->portalUrl())->assertOk()->assertDontSee('Payment requested');
});
