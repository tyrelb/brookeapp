<?php

use App\Actions\CompleteTrainingSession;
use App\Actions\ReopenTrainingSession;
use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WalletTransaction;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5, 'gst_registered' => true]);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $this->wallet = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
    PlanRate::factory()->create(['plan_id' => $this->wallet->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    PlanRate::factory()->create(['plan_id' => $this->wallet->id, 'service_id' => $this->service->id, 'headcount' => 2, 'unit_price' => 30]);
    $this->monthly = Plan::factory()->monthly()->create(['user_id' => $this->trainer->id]);

    $this->session = TrainingSession::factory()->create([
        'user_id' => $this->trainer->id,
        'service_id' => $this->service->id,
        'starts_at' => '2026-09-01 09:00:00',
    ]);
});

function walletClient($test): Client
{
    return Client::factory()->create(['user_id' => $test->trainer->id, 'plan_id' => $test->wallet->id]);
}

it('charges each wallet attendee the partner rate plus gst', function () {
    $a = walletClient($this);
    $b = walletClient($this);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $a->id]);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $b->id]);

    $session = app(CompleteTrainingSession::class)->handle($this->session);

    expect($session->status)->toBe(SessionStatus::Completed)
        ->and($session->completed_at)->not->toBeNull()
        ->and($a->balance())->toBe(-31.50)
        ->and($b->balance())->toBe(-31.50);

    $tx = WalletTransaction::where('client_id', $a->id)->first();
    expect($tx->type)->toBe(TransactionType::SessionCharge)
        ->and((float) $tx->amount)->toBe(-31.50)
        ->and((float) $tx->subtotal)->toBe(30.00)
        ->and((float) $tx->gst_amount)->toBe(1.50)
        ->and($tx->transacted_on->toDateString())->toBe('2026-09-01')
        ->and($tx->training_session_id)->toBe($session->id)
        ->and($tx->description)->toBe('Personal Training (Partner)');

    $attendee = $session->attendees->firstWhere('client_id', $a->id);
    expect((float) $attendee->total)->toBe(31.50)
        ->and($attendee->wallet_transaction_id)->toBe($tx->id);
});

it('records monthly attendees at zero and only charges wallet attendees', function () {
    $wallet = walletClient($this);
    $member = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->monthly->id]);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $wallet->id]);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $member->id]);

    $session = app(CompleteTrainingSession::class)->handle($this->session);

    expect($wallet->balance())->toBe(-31.50) // partner rate: two people attended
        ->and($member->balance())->toBe(0.0)
        ->and(WalletTransaction::count())->toBe(1)
        ->and((float) $session->attendees->firstWhere('client_id', $member->id)->total)->toBe(0.0);
});

it('ignores no-shows when counting headcount', function () {
    $a = walletClient($this);
    $b = walletClient($this);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $a->id]);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $b->id, 'attended' => false]);

    app(CompleteTrainingSession::class)->handle($this->session);

    expect($a->balance())->toBe(-63.00)
        ->and($b->balance())->toBe(0.0);
});

it('applies a price override', function () {
    $a = walletClient($this);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $a->id, 'price_override' => 50]);

    app(CompleteTrainingSession::class)->handle($this->session);

    expect($a->balance())->toBe(-52.50);
});

it('refuses to complete twice', function () {
    $a = walletClient($this);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $a->id]);

    app(CompleteTrainingSession::class)->handle($this->session);
    app(CompleteTrainingSession::class)->handle($this->session->fresh());
})->throws(BillingException::class);

it('reopening voids the charges so the session can be corrected and re-completed', function () {
    $a = walletClient($this);
    $b = walletClient($this);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $a->id]);
    $bAttendance = SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $b->id]);

    app(CompleteTrainingSession::class)->handle($this->session);
    expect($a->balance())->toBe(-31.50);

    $session = app(ReopenTrainingSession::class)->handle($this->session->fresh());
    expect($session->status)->toBe(SessionStatus::Scheduled)
        ->and($a->balance())->toBe(0.0)
        ->and(WalletTransaction::whereNotNull('voided_at')->count())->toBe(2);

    // B did not actually show up: correct attendance and complete again.
    $bAttendance->update(['attended' => false]);
    app(CompleteTrainingSession::class)->handle($session->fresh());

    expect($a->balance())->toBe(-63.00)
        ->and($b->balance())->toBe(0.0)
        ->and(WalletTransaction::active()->count())->toBe(1);
});

it('charges no gst when the trainer is not gst registered', function () {
    $this->trainer->update(['gst_registered' => false]);
    $a = walletClient($this);
    SessionAttendee::factory()->create(['training_session_id' => $this->session->id, 'client_id' => $a->id]);

    app(CompleteTrainingSession::class)->handle($this->session->fresh());

    expect($a->balance())->toBe(-60.00);
});
