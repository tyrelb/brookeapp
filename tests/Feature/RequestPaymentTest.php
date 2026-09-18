<?php

use App\Actions\CreateInvoice;
use App\Enums\InvoiceStatus;
use App\Livewire\Clients\Show;
use App\Mail\InvoiceMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\PaymentRequestSuggester;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->trainer = User::factory()->create([
        'name' => 'Brooke',
        'business_name' => 'Brooke Fitness',
        'gst_rate' => 5,
        'gst_registered' => true,
        'payment_methods' => ['cash', 'etransfer'],
        'etransfer_email' => 'pay@brooke.example',
    ]);
    $this->actingAs($this->trainer);

    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training (60 mins)']);
});

/** A package plan priced like the screenshot: 50 sessions at $87.50 a single session. */
function packagePlan(User $trainer, Service $service, ?int $sessions = 50): Plan
{
    $plan = Plan::factory()->wallet()->create([
        'user_id' => $trainer->id,
        'name' => 'Package - 50 Sessions',
        'package_sessions' => $sessions,
    ]);
    $plan->rates()->create(['service_id' => $service->id, 'headcount' => 1, 'unit_price' => 87.50]);
    $plan->rates()->create(['service_id' => $service->id, 'headcount' => 2, 'unit_price' => 60.00]);

    return $plan;
}

it('suggests the monthly fee plus gst, whatever the balance', function () {
    $plan = Plan::factory()->monthly(1105.00)->create(['user_id' => $this->trainer->id, 'name' => 'Monthly - Premium']);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    $suggestion = app(PaymentRequestSuggester::class)->for($client);

    expect($suggestion['subtotal'])->toBe(1105.00)
        ->and($suggestion['gst'])->toBe(55.25)
        ->and($suggestion['total'])->toBe(1160.25)
        ->and($suggestion['lines'][0]['description'])->toBe('Monthly - Premium — monthly fee');
});

it('suggests the package: sessions times the single rate, plus gst', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    $suggestion = app(PaymentRequestSuggester::class)->for($client);

    expect($suggestion['subtotal'])->toBe(4375.00)
        ->and($suggestion['gst'])->toBe(218.75)
        ->and($suggestion['total'])->toBe(4593.75)
        ->and($suggestion['lines'][0]['description'])->toBe('50 sessions × $87.50')
        ->and($suggestion['lines'][0]['quantity'])->toBe(50);
});

it('suggests nothing when a pay-as-you-go plan has no package size', function () {
    $plan = packagePlan($this->trainer, $this->service, sessions: null);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    expect(app(PaymentRequestSuggester::class)->for($client))->toBeNull();

    Livewire::test(Show::class, ['client' => $client])->assertSet('requestAmount', '');
});

it('prefills the modal with the suggestion and sends a numbered invoice', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $client = Client::factory()->create([
        'user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'email' => 'erfan@example.com',
    ]);

    Livewire::test(Show::class, ['client' => $client])
        ->assertSet('requestAmount', '4375.00')
        ->assertSet('requestDueOn', today()->addDays(7)->toDateString())
        ->call('requestPayment')
        ->assertHasNoErrors();

    $invoice = Invoice::where('client_id', $client->id)->firstOrFail();

    expect($invoice->number)->toBe('INV-0001')
        ->and((float) $invoice->subtotal)->toBe(4375.00)
        ->and((float) $invoice->gst_amount)->toBe(218.75)
        ->and((float) $invoice->total)->toBe(4593.75)
        ->and((float) $invoice->gst_rate)->toBe(5.00)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($invoice->status())->toBe(InvoiceStatus::Sent);

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo('erfan@example.com') && $mail->invoice->is($invoice));
});

it('keeps the itemised breakdown, but not when the trainer types their own amount', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'email' => 'e@example.com']);

    $asSuggested = app(CreateInvoice::class)->handle($client, 4375.00);
    $overridden = app(CreateInvoice::class)->handle($client, 2000.00);

    expect($asSuggested->lines[0]['quantity'])->toBe(50)
        ->and($asSuggested->lines[0]['description'])->toBe('50 sessions × $87.50')
        ->and($overridden->lines[0]['quantity'])->toBeNull()
        ->and($overridden->lines[0]['description'])->toBe('Fitness Wallet top-up')
        ->and((float) $overridden->total)->toBe(2100.00);
});

it('numbers invoices per trainer, so two trainers both start at INV-0001', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $mine = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);

    $other = User::factory()->create(['gst_rate' => 5]);
    $otherPlan = Plan::factory()->monthly(100.00)->create(['user_id' => $other->id]);
    $theirs = Client::factory()->create(['user_id' => $other->id, 'plan_id' => $otherPlan->id]);

    $first = app(CreateInvoice::class)->handle($mine, 500);
    $second = app(CreateInvoice::class)->handle($mine, 500);
    $third = app(CreateInvoice::class)->handle($theirs, 100);

    expect($first->number)->toBe('INV-0001')
        ->and($second->number)->toBe('INV-0002')
        ->and($third->number)->toBe('INV-0001')
        ->and($third->user_id)->toBe($other->id);
});

it('refuses to send when the client has no email address', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'email' => null]);

    Livewire::test(Show::class, ['client' => $client])->call('requestPayment');

    expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rejects a due date before the issue date', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'email' => 'e@example.com']);

    Livewire::test(Show::class, ['client' => $client])
        ->set('requestIssuedOn', '2026-09-17')
        ->set('requestDueOn', '2026-09-10')
        ->call('requestPayment')
        ->assertHasErrors('requestDueOn');
});

it('emails the amount, the e-transfer address and the wallet link', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $client = Client::factory()->create([
        'user_id' => $this->trainer->id, 'plan_id' => $plan->id,
        'first_name' => 'Erfan', 'email' => 'erfan@example.com',
    ]);

    $invoice = app(CreateInvoice::class)->handle($client, 4375.00, null, today()->addDays(7), 'Whenever suits.');

    $rendered = (new InvoiceMail($invoice->fresh(['client.trainer', 'client.plan.rates'])))->render();

    expect($rendered)->toContain('$4,593.75')
        ->toContain('50 sessions')
        ->toContain('INV-0001')
        ->toContain('pay@brooke.example')
        ->toContain($client->fresh()->portal_token)
        // Its own paragraph, not folded onto the invoice-number line above it.
        ->toMatch('/<p[^>]*>\s*Whenever suits\.\s*<\/p>/');
});

it('voids a request without touching money already received', function () {
    $plan = packagePlan($this->trainer, $this->service);
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'email' => 'e@example.com']);
    $invoice = app(CreateInvoice::class)->handle($client, 4375.00);

    Livewire::test(Show::class, ['client' => $client])->call('voidInvoice', $invoice->id);

    expect($invoice->fresh()->status())->toBe(InvoiceStatus::Void)
        ->and(Invoice::outstanding()->count())->toBe(0);
});

it('will not let one trainer touch another trainer\'s request', function () {
    $other = User::factory()->create(['gst_rate' => 5]);
    $otherPlan = Plan::factory()->monthly(100.00)->create(['user_id' => $other->id]);
    $theirs = Client::factory()->create(['user_id' => $other->id, 'plan_id' => $otherPlan->id, 'email' => 'x@example.com']);
    $theirInvoice = app(CreateInvoice::class)->handle($theirs, 100);

    $plan = packagePlan($this->trainer, $this->service);
    $mine = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id, 'email' => 'e@example.com']);

    Livewire::test(Show::class, ['client' => $mine])->call('remindInvoice', $theirInvoice->id);
})->throws(ModelNotFoundException::class);
