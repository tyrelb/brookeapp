<?php

use App\Actions\PostAdjustment;
use App\Actions\RecordPayment;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Exceptions\PaymentMethodNotAcceptedException;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'payment_methods' => ['cash', 'etransfer']]);
    $plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    $this->client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $plan->id]);
});

it('records a deposit with its embedded gst', function () {
    $tx = app(RecordPayment::class)->handle($this->client, 300, PaymentMethod::ETransfer, '2026-09-03', 'REF123');

    expect($tx->type)->toBe(TransactionType::Payment)
        ->and((float) $tx->amount)->toBe(300.00)
        ->and((float) $tx->gst_amount)->toBe(14.29)
        ->and((float) $tx->subtotal)->toBe(285.71)
        ->and($tx->payment_method)->toBe(PaymentMethod::ETransfer)
        ->and($tx->reference)->toBe('REF123')
        ->and($tx->transacted_on->toDateString())->toBe('2026-09-03')
        ->and($tx->description)->toBe('Fitness Wallet deposit')
        ->and($this->client->balance())->toBe(300.00);
});

it('rejects a payment method the trainer has not enabled', function () {
    app(RecordPayment::class)->handle($this->client, 100, PaymentMethod::Cheque);
})->throws(PaymentMethodNotAcceptedException::class);

it('rejects non-positive payments', function () {
    app(RecordPayment::class)->handle($this->client, 0, PaymentMethod::Cash);
})->throws(BillingException::class);

it('posts adjustments without gst and refunds with embedded gst', function () {
    $credit = app(PostAdjustment::class)->handle($this->client, 10, 'Goodwill credit');
    $refund = app(PostAdjustment::class)->handle($this->client, 105, 'Refund unused balance', TransactionType::Refund, null, PaymentMethod::Cash);

    expect((float) $credit->amount)->toBe(10.00)
        ->and((float) $credit->gst_amount)->toBe(0.0)
        ->and($credit->type)->toBe(TransactionType::Adjustment)
        ->and((float) $refund->amount)->toBe(-105.00)
        ->and((float) $refund->gst_amount)->toBe(5.00)
        ->and($refund->payment_method)->toBe(PaymentMethod::Cash)
        ->and($this->client->balance())->toBe(-95.00);
});

it('requires a note on adjustments', function () {
    app(PostAdjustment::class)->handle($this->client, 10, '   ');
})->throws(BillingException::class);
