<?php

use App\Actions\CreateInvoice;
use App\Actions\ReceiveInvoicePayment;
use App\Actions\RecordPayment;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BillingException;
use App\Livewire\Clients\Show;
use App\Livewire\Invoices\Index;
use App\Mail\InvoiceMail;
use App\Mail\PaymentReceivedMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->trainer = User::factory()->create([
        'business_name' => 'Brooke Fitness',
        'gst_rate' => 5,
        'gst_registered' => true,
        'payment_methods' => ['cash', 'etransfer'],
        'etransfer_email' => 'pay@brooke.example',
    ]);
    $this->actingAs($this->trainer);

    $service = Service::factory()->create(['user_id' => $this->trainer->id]);
    $this->plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id, 'package_sessions' => 20]);
    $this->plan->rates()->create(['service_id' => $service->id, 'headcount' => 1, 'unit_price' => 60]);

    $this->ava = Client::factory()->create([
        'user_id' => $this->trainer->id, 'plan_id' => $this->plan->id,
        'first_name' => 'Ava', 'last_name' => 'Nguyen', 'email' => 'ava@example.com',
    ]);
    $this->ben = Client::factory()->create([
        'user_id' => $this->trainer->id, 'plan_id' => $this->plan->id,
        'first_name' => 'Ben', 'last_name' => 'Okafor', 'email' => 'ben@example.com',
    ]);
});

/** $1,200 + $60 GST = $1,260, due in a week unless told otherwise. */
function invoiceFor(Client $client, ?string $due = null): Invoice
{
    return app(CreateInvoice::class)->handle($client, 1200, today()->subDays(10), $due ?? today()->addDays(7)->toDateString());
}

it('is linked from the sidebar and loads', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee(route('invoices.index'));
    $this->get(route('invoices.index'))->assertOk()->assertSee('Outstanding');
});

it('shows open invoices by default and filters by status', function () {
    $open = invoiceFor($this->ava);                                   // INV-0001
    $overdue = invoiceFor($this->ben, today()->subDay()->toDateString()); // INV-0002
    $paid = invoiceFor($this->ava);                                   // INV-0003
    app(RecordPayment::class)->handle($this->ava, 1260, PaymentMethod::Cash, null, null, null, $paid);
    $void = invoiceFor($this->ben);                                   // INV-0004
    $void->forceFill(['voided_at' => now()])->save();

    Livewire::test(Index::class)
        ->assertSee(['INV-0001', 'INV-0002'])->assertDontSee(['INV-0003', 'INV-0004'])
        ->set('status', 'overdue')->assertSee('INV-0002')->assertDontSee(['INV-0001', 'INV-0003', 'INV-0004'])
        ->set('status', 'paid')->assertSee('INV-0003')->assertDontSee(['INV-0001', 'INV-0002', 'INV-0004'])
        ->set('status', 'void')->assertSee('INV-0004')->assertDontSee(['INV-0001', 'INV-0002', 'INV-0003'])
        ->set('status', 'all')->assertSee(['INV-0001', 'INV-0002', 'INV-0003', 'INV-0004']);
});

it('adds up what is outstanding and overdue', function () {
    invoiceFor($this->ava);
    $overdue = invoiceFor($this->ben, today()->subDay()->toDateString());
    app(RecordPayment::class)->handle($this->ben, 260, PaymentMethod::Cash, null, null, null, $overdue);

    Livewire::test(Index::class)
        ->assertViewHas('summary', fn (array $s) => $s['outstanding'] === 2260.00
            && $s['open'] === 2
            && $s['overdue'] === 1000.00
            && $s['overdueCount'] === 1
            && $s['receivedThisMonth'] === 260.00);
});

it('searches by number and by client name', function () {
    invoiceFor($this->ava);
    invoiceFor($this->ben);

    Livewire::test(Index::class)
        ->set('search', 'INV-0002')->assertSee('Ben Okafor')->assertDontSee('Ava Nguyen')
        ->set('search', 'ava')->assertSee('INV-0001')->assertDontSee('INV-0002');
});

it('never shows or touches another trainer\'s invoices', function (string $method) {
    $other = User::factory()->create(['gst_rate' => 5]);
    $theirPlan = Plan::factory()->monthly(100)->create(['user_id' => $other->id]);
    $theirClient = Client::factory()->create(['user_id' => $other->id, 'plan_id' => $theirPlan->id, 'email' => 'x@example.com']);
    $theirs = app(CreateInvoice::class)->handle($theirClient, 100);
    invoiceFor($this->ava);

    Livewire::test(Index::class)->set('status', 'all')
        ->assertViewHas('invoices', fn ($page) => $page->pluck('id')->doesntContain($theirs->id))
        ->call($method, $theirs->id);
})->with(['remindInvoice', 'openMarkPaid', 'voidInvoice', 'deleteInvoice'])
    ->throws(ModelNotFoundException::class);

it('reminds a client and records when it was last chased', function () {
    $invoice = invoiceFor($this->ava);

    Livewire::test(Index::class)->call('remindInvoice', $invoice->id)->call('remindInvoice', $invoice->id);

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $m) => $m->reminder && $m->hasTo('ava@example.com'));
    expect($invoice->fresh()->reminder_count)->toBe(2)
        ->and($invoice->fresh()->reminded_at)->not->toBeNull();
});

it('will not remind about a paid or voided invoice', function () {
    $paid = invoiceFor($this->ava);
    app(RecordPayment::class)->handle($this->ava, 1260, PaymentMethod::Cash, null, null, null, $paid);
    $void = invoiceFor($this->ben);
    $void->forceFill(['voided_at' => now()])->save();

    Livewire::test(Index::class)->call('remindInvoice', $paid->id)->call('remindInvoice', $void->id);

    Mail::assertNothingQueued();
    expect($paid->fresh()->reminder_count)->toBe(0);
});

it('words a reminder as one, with what is left to pay', function () {
    $invoice = invoiceFor($this->ava, today()->subDays(2)->toDateString());
    app(RecordPayment::class)->handle($this->ava, 1000, PaymentMethod::Cash, null, null, null, $invoice);

    $mail = new InvoiceMail($invoice->fresh(), reminder: true);

    expect($mail->envelope()->subject)->toStartWith('Reminder: INV-0001 is overdue')
        ->and($mail->render())->toContain('Just a reminder')
        ->toContain('Received so far')
        ->toContain('$260.00');
});

it('marks an invoice paid by recording the payment, and thanks the client', function () {
    $invoice = invoiceFor($this->ava);

    Livewire::test(Index::class)
        ->call('openMarkPaid', $invoice->id)
        ->assertSet('markPaidAmount', '1260.00')
        ->assertSet('markPaidMethod', 'cash')
        ->assertSet('markPaidNotify', true)
        ->set('markPaidMethod', 'etransfer')
        ->set('markPaidReference', 'CA123')
        ->call('markPaid')
        ->assertHasNoErrors();

    $payment = $invoice->payments()->sole();

    expect($invoice->fresh()->status())->toBe(InvoiceStatus::Paid)
        ->and((float) $payment->amount)->toBe(1260.00)
        ->and($payment->payment_method)->toBe(PaymentMethod::ETransfer)
        ->and($payment->reference)->toBe('CA123')
        ->and($this->ava->balance())->toBe(1260.00);

    Mail::assertQueued(PaymentReceivedMail::class, fn (PaymentReceivedMail $m) => $m->hasTo('ava@example.com') && $m->payment->is($payment));
});

it('can mark paid without emailing, and a smaller amount leaves it partly paid', function () {
    $invoice = invoiceFor($this->ava);

    Livewire::test(Index::class)
        ->call('openMarkPaid', $invoice->id)
        ->set('markPaidAmount', '500')
        ->set('markPaidNotify', false)
        ->call('markPaid')
        ->assertHasNoErrors();

    expect($invoice->fresh()->status())->toBe(InvoiceStatus::PartlyPaid)
        ->and($invoice->fresh()->outstandingAmount())->toBe(760.00);
    Mail::assertNothingQueued();
});

it('will not take a payment against a voided invoice', function () {
    $invoice = invoiceFor($this->ava);
    $invoice->forceFill(['voided_at' => now()])->save();

    Livewire::test(Index::class)->call('openMarkPaid', $invoice->id)->assertSet('markPaidId', null);

    expect(fn () => app(ReceiveInvoicePayment::class)->handle($invoice, 100, PaymentMethod::Cash))
        ->toThrow(BillingException::class);
});

it('deletes an unpaid invoice from every list and from the wallet page', function () {
    $invoice = invoiceFor($this->ava);
    $url = $this->ava->portalUrl();

    Livewire::test(Index::class)->call('deleteInvoice', $invoice->id);

    $this->assertSoftDeleted($invoice);
    Livewire::test(Index::class)->set('status', 'all')->assertDontSee('INV-0001');
    auth()->logout();
    $this->get($url)->assertOk()->assertDontSee('INV-0001');
});

it('will not delete an invoice once money has been received', function () {
    $invoice = invoiceFor($this->ava);
    app(RecordPayment::class)->handle($this->ava, 100, PaymentMethod::Cash, null, null, null, $invoice);

    Livewire::test(Index::class)->call('deleteInvoice', $invoice->id);

    $this->assertNotSoftDeleted($invoice);
});

it('never reissues a deleted invoice\'s number', function () {
    invoiceFor($this->ava);
    $newest = invoiceFor($this->ben);
    Livewire::test(Index::class)->call('deleteInvoice', $newest->id);

    expect(invoiceFor($this->ava)->number)->toBe('INV-0003');
});

it('thanks the client with the amount, what is left and their balance', function () {
    $invoice = invoiceFor($this->ava);

    $partial = app(ReceiveInvoicePayment::class)->handle($invoice, 1000, PaymentMethod::ETransfer, notify: false);
    $partialMail = (new PaymentReceivedMail($invoice->fresh(), $partial))->render();

    expect($partialMail)->toContain('$1,000.00')->toContain('still to pay')->toContain('$260.00');

    $rest = app(ReceiveInvoicePayment::class)->handle($invoice, 260, PaymentMethod::Cash, notify: false);
    $fullMail = new PaymentReceivedMail($invoice->fresh(), $rest);

    expect($fullMail->envelope()->subject)->toBe('Payment received for INV-0001 — Brooke Fitness')
        ->and($fullMail->render())->toContain('paid in full')->toContain('Fitness Wallet balance')->toContain('$1,260.00');
});

it('shows a paid invoice on the wallet page with the date it was paid', function () {
    $invoice = invoiceFor($this->ava);
    app(ReceiveInvoicePayment::class)->handle($invoice, 1260, PaymentMethod::Cash, today()->subDay(), notify: false);
    $url = $this->ava->portalUrl();
    auth()->logout();

    $this->get($url)->assertOk()
        ->assertSee('INV-0001')
        ->assertSee('Paid '.today()->subDay()->format('M j'))
        ->assertDontSee('Payment requested');
});

it('offers the same actions on the client page', function () {
    $invoice = invoiceFor($this->ava);

    Livewire::test(Show::class, ['client' => $this->ava])
        ->assertSee(['Remind', 'Mark paid', 'Void', 'Delete'])
        ->call('remindInvoice', $invoice->id)
        ->call('openMarkPaid', $invoice->id)
        ->call('markPaid')
        ->assertHasNoErrors()
        ->assertSet('paymentInvoiceId', '');

    expect($invoice->fresh()->status())->toBe(InvoiceStatus::Paid);
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $m) => $m->reminder);
    Mail::assertQueued(PaymentReceivedMail::class);
});

it('will not void an invoice that is already paid', function () {
    $invoice = invoiceFor($this->ava);
    app(RecordPayment::class)->handle($this->ava, 1260, PaymentMethod::Cash, null, null, null, $invoice);

    Livewire::test(Index::class)->set('status', 'paid')
        ->assertSee('INV-0001')
        ->assertDontSeeHtml('voidInvoice(')
        ->call('voidInvoice', $invoice->id);

    expect($invoice->fresh()->status())->toBe(InvoiceStatus::Paid);
});
