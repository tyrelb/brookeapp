<?php

use App\Actions\PostMonthlyFee;
use App\Enums\TransactionType;
use App\Exceptions\BillingException;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Models\WalletTransaction;

beforeEach(function () {
    $this->trainer = User::factory()->create(['gst_rate' => 5]);
    $this->monthly = Plan::factory()->monthly(300, 5)->create(['user_id' => $this->trainer->id, 'name' => 'Monthly Unlimited']);
    $this->wallet = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id]);
});

it('posts the fee plus gst once per period', function () {
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->monthly->id, 'started_at' => '2026-01-01']);

    $first = app(PostMonthlyFee::class)->handle($client, '2026-09-15');
    $second = app(PostMonthlyFee::class)->handle($client, '2026-09-01');

    expect($first->id)->toBe($second->id)
        ->and($first->type)->toBe(TransactionType::MonthlyFee)
        ->and((float) $first->amount)->toBe(-315.00)
        ->and((float) $first->subtotal)->toBe(300.00)
        ->and((float) $first->gst_amount)->toBe(15.00)
        ->and($first->billing_period)->toBe('2026-09')
        ->and($first->transacted_on->toDateString())->toBe('2026-09-05')
        ->and($first->description)->toBe('Monthly Unlimited — September 2026')
        ->and($client->balance())->toBe(-315.00);
});

it('refuses to post a fee for a wallet client', function () {
    $client = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->wallet->id]);

    app(PostMonthlyFee::class)->handle($client);
})->throws(BillingException::class);

it('the command respects billing day, skips inactive and wallet clients, and is idempotent', function () {
    $due = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->monthly->id, 'started_at' => '2026-01-01']);
    $inactive = Client::factory()->inactive()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->monthly->id, 'started_at' => '2026-01-01']);
    $wallet = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->wallet->id]);
    $joinedLate = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->monthly->id, 'started_at' => '2026-09-20']);

    // Other trainer's client must be handled independently (and not leak).
    $other = User::factory()->create();
    $otherPlan = Plan::factory()->monthly(200, 1)->create(['user_id' => $other->id]);
    $otherClient = Client::factory()->create(['user_id' => $other->id, 'plan_id' => $otherPlan->id, 'started_at' => '2026-01-01']);

    // Before billing day (5th): nothing for this trainer, but the other trainer's day-1 fee posts.
    $this->artisan('billing:post-monthly-fees', ['--date' => '2026-09-03'])->assertSuccessful();
    expect(WalletTransaction::where('client_id', $due->id)->count())->toBe(0)
        ->and(WalletTransaction::where('client_id', $otherClient->id)->count())->toBe(1);

    // On billing day: due client posts; inactive, wallet and late joiners do not.
    $this->artisan('billing:post-monthly-fees', ['--date' => '2026-09-05'])->assertSuccessful();
    $this->artisan('billing:post-monthly-fees', ['--date' => '2026-09-06'])->assertSuccessful();

    expect(WalletTransaction::where('client_id', $due->id)->count())->toBe(1)
        ->and(WalletTransaction::where('client_id', $inactive->id)->count())->toBe(0)
        ->and(WalletTransaction::where('client_id', $wallet->id)->count())->toBe(0)
        ->and(WalletTransaction::where('client_id', $joinedLate->id)->count())->toBe(0)
        ->and(WalletTransaction::where('client_id', $otherClient->id)->count())->toBe(1);

    // Next month the late joiner is billed.
    $this->artisan('billing:post-monthly-fees', ['--date' => '2026-10-05'])->assertSuccessful();
    expect(WalletTransaction::where('client_id', $joinedLate->id)->count())->toBe(1)
        ->and(WalletTransaction::where('client_id', $due->id)->count())->toBe(2);
});
