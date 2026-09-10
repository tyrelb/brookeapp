<?php

use App\Actions\PostAdjustment;
use App\Actions\PostMonthlyFee;
use App\Actions\RecordPayment;
use App\Actions\UpdateTransaction;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Livewire\Clients\Show;
use App\Models\Client;
use App\Models\Plan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WalletTransactionRevision;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'payment_methods' => ['cash', 'etransfer']]);
    $this->actingAs($this->trainer);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    $this->client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);
});

it('logs a created revision for every new ledger row', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 300, PaymentMethod::ETransfer, '2026-06-18', 'Invoice 31');

    expect($tx->revisions)->toHaveCount(1)
        ->and($tx->revisions->first()->action)->toBe(WalletTransactionRevision::ACTION_CREATED)
        ->and($tx->revisions->first()->changed_by)->toBe($this->trainer->id);
});

it('edits a payment, recalculates gst and records what changed', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 300, PaymentMethod::ETransfer, '2026-06-18', 'Invoice 31');

    app(UpdateTransaction::class)->handle(
        $tx, 4593.75, '2026-06-19', 'Client sent a corrected invoice',
        'Invoice June 19, 2026 #31', PaymentMethod::Cash, 'Invoice 31b',
    );

    $tx->refresh();
    expect((float) $tx->amount)->toBe(4593.75)
        ->and((float) $tx->gst_amount)->toBe(218.75)
        ->and((float) $tx->subtotal)->toBe(4375.00)
        ->and($tx->payment_method)->toBe(PaymentMethod::Cash)
        ->and($tx->reference)->toBe('Invoice 31b')
        ->and($tx->transacted_on->toDateString())->toBe('2026-06-19')
        ->and($this->client->balance())->toBe(4593.75);

    $revision = $tx->revisions()->where('action', WalletTransactionRevision::ACTION_UPDATED)->sole();
    expect($revision->reason)->toBe('Client sent a corrected invoice')
        ->and($revision->changed_by)->toBe($this->trainer->id)
        ->and((float) $revision->changed_fields['amount']['from'])->toBe(300.00)
        ->and((float) $revision->changed_fields['amount']['to'])->toBe(4593.75)
        ->and($revision->changed_fields['payment_method'])->toBe(['from' => 'etransfer', 'to' => 'cash'])
        ->and($revision->changed_fields['transacted_on'])->toBe(['from' => '2026-06-18', 'to' => '2026-06-19'])
        ->and($revision->summaryLines())->toContain('Amount: $300.00 → $4,593.75')
        ->and($revision->summaryLines())->toContain('Date: Jun 18, 2026 → Jun 19, 2026');
});

it('keeps a monthly fee entered before gst and a refund negative', function () {
    $monthly = Plan::factory()->monthly(300, 1)->create(['user_id' => $this->trainer->id]);
    $this->client->update(['plan_id' => $monthly->id]);

    $fee = app(PostMonthlyFee::class)->handle($this->client->fresh(), '2026-06-01');
    app(UpdateTransaction::class)->handle($fee, 400, '2026-06-01', 'Rate went up mid-month');

    expect((float) $fee->fresh()->subtotal)->toBe(400.00)
        ->and((float) $fee->fresh()->gst_amount)->toBe(20.00)
        ->and((float) $fee->fresh()->amount)->toBe(-420.00);

    $refund = app(PostAdjustment::class)->handle($this->client, 105, 'Refund unused balance', TransactionType::Refund, '2026-06-02', PaymentMethod::Cash);
    app(UpdateTransaction::class)->handle($refund, 210, '2026-06-02', 'Refunded twice as much', 'Refund unused balance', PaymentMethod::Cash);

    expect((float) $refund->fresh()->amount)->toBe(-210.00)
        ->and((float) $refund->fresh()->gst_amount)->toBe(10.00);
});

it('flips an adjustment between credit and debit', function () {
    $credit = app(PostAdjustment::class)->handle($this->client, 50, 'Goodwill credit', TransactionType::Adjustment, '2026-06-05');

    app(UpdateTransaction::class)->handle($credit, -50, '2026-06-05', 'Should have been a debit', 'Goodwill credit');

    expect((float) $credit->fresh()->amount)->toBe(-50.00)
        ->and((float) $credit->fresh()->gst_amount)->toBe(0.0)
        ->and($this->client->balance())->toBe(-50.00);
});

it('refuses edits that would lose the audit trail or break the ledger', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 100, PaymentMethod::Cash, '2026-06-18');

    expect(fn () => app(UpdateTransaction::class)->handle($tx, 150, '2026-06-18', '  '))
        ->toThrow(BillingException::class);
    expect(fn () => app(UpdateTransaction::class)->handle($tx, 100, '2026-06-18', 'No change at all'))
        ->toThrow(BillingException::class);

    $tx->update(['voided_at' => now()]);
    expect(fn () => app(UpdateTransaction::class)->handle($tx, 150, '2026-06-18', 'Too late'))
        ->toThrow(BillingException::class);
});

it('will not edit a session charge', function () {
    $session = TrainingSession::factory()->create(['user_id' => $this->trainer->id]);
    $charge = WalletTransaction::factory()->create([
        'user_id' => $this->trainer->id,
        'client_id' => $this->client->id,
        'type' => TransactionType::SessionCharge,
        'amount' => -80,
        'training_session_id' => $session->id,
    ]);

    expect($charge->isEditable())->toBeFalse();
    expect(fn () => app(UpdateTransaction::class)->handle($charge, 90, '2026-06-18', 'Wrong rate'))
        ->toThrow(BillingException::class);
});

it('edits a transaction and shows its history from the client screen', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 300, PaymentMethod::ETransfer, '2026-06-18', 'Invoice 31');

    Livewire::test(Show::class, ['client' => $this->client])
        ->call('editTransaction', $tx->id)
        ->assertSet('editAmount', '300')
        ->assertSet('editMethod', 'etransfer')
        ->set('editAmount', '325')
        ->set('editReason', 'Client paid the corrected invoice')
        ->call('updateTransaction')
        ->assertHasNoErrors()
        ->call('showHistory', $tx->id)
        ->assertSee('Client paid the corrected invoice')
        ->assertSee('Amount: $300.00 → $325.00');

    expect((float) $tx->fresh()->amount)->toBe(325.00);
});

it('requires a reason before saving an edit', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 300, PaymentMethod::Cash, '2026-06-18');

    Livewire::test(Show::class, ['client' => $this->client])
        ->call('editTransaction', $tx->id)
        ->set('editAmount', '325')
        ->call('updateTransaction')
        ->assertHasErrors('editReason');

    expect((float) $tx->fresh()->amount)->toBe(300.00);
});

it('records a revision when a transaction is voided', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 300, PaymentMethod::Cash, '2026-06-18');

    Livewire::test(Show::class, ['client' => $this->client])->call('voidTransaction', $tx->id);

    expect($tx->fresh()->isVoided())->toBeTrue()
        ->and($tx->revisions()->where('action', WalletTransactionRevision::ACTION_VOIDED)->exists())->toBeTrue();
});

it('will not touch a transaction belonging to another client', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 300, PaymentMethod::Cash, '2026-06-18');
    $other = Client::factory()->create(['user_id' => $this->trainer->id]);

    Livewire::test(Show::class, ['client' => $other])->call('editTransaction', $tx->id);
})->throws(ModelNotFoundException::class);
