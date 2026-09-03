<?php

use App\Actions\CompleteTrainingSession;
use App\Actions\RecordPayment;
use App\Enums\PaymentMethod;
use App\Livewire\Clients\Show;
use App\Mail\SessionCompletedMail;
use App\Mail\WalletLinkMail;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanRate;
use App\Models\Service;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->trainer = User::factory()->create([
        'business_name' => 'Brooke Fitness',
        'booking_instructions' => 'Text me at 604-555-0199.',
        'etransfer_email' => 'pay@brooke.example',
        'payment_methods' => ['cash', 'etransfer'],
    ]);
    $this->service = Service::factory()->create(['user_id' => $this->trainer->id, 'name' => 'Personal Training']);
    $this->plan = Plan::factory()->wallet()->create(['user_id' => $this->trainer->id, 'name' => 'Standard']);
    PlanRate::factory()->create(['plan_id' => $this->plan->id, 'service_id' => $this->service->id, 'headcount' => 1, 'unit_price' => 60]);
    $this->ava = Client::factory()->create(['user_id' => $this->trainer->id, 'plan_id' => $this->plan->id, 'first_name' => 'Ava', 'last_name' => 'Nguyen', 'email' => 'ava@example.com']);
    $this->other = Client::factory()->create(['user_id' => $this->trainer->id, 'first_name' => 'Zed', 'last_name' => 'Secret']);

    app(RecordPayment::class)->handle($this->ava, 300, PaymentMethod::ETransfer, '2026-09-01');
    $done = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => '2026-09-02 09:00:00']);
    SessionAttendee::factory()->create(['training_session_id' => $done->id, 'client_id' => $this->ava->id]);
    app(CompleteTrainingSession::class)->handle($done);

    $this->upcoming = TrainingSession::factory()->create(['user_id' => $this->trainer->id, 'service_id' => $this->service->id, 'starts_at' => now()->addDays(3)->setTime(10, 0)]);
    SessionAttendee::factory()->create(['training_session_id' => $this->upcoming->id, 'client_id' => $this->ava->id]);
});

it('opens the wallet page from the magic link without logging in', function () {
    $url = $this->ava->portalUrl();

    expect($this->ava->fresh()->portal_token)->toHaveLength(48);

    $this->get($url)
        ->assertOk()
        ->assertSee('Brooke Fitness')
        ->assertSee('Ava Nguyen')
        ->assertSee('$237.00')              // 300 - 63
        ->assertSee('Fitness Wallet deposit')
        ->assertSee('Personal Training')
        ->assertSee('Text me at 604-555-0199')
        ->assertSee('pay@brooke.example')
        ->assertSee($this->upcoming->starts_at->format('l, F j'))
        ->assertDontSee('Zed')
        ->assertDontSee('Secret')
        ->assertSee('noindex', false);
});

it('rejects unknown or reset tokens and suspended trainers', function () {
    $this->get('/wallet/'.str_repeat('a', 48))->assertNotFound();

    $old = $this->ava->portalUrl();
    $this->ava->regeneratePortalToken();
    $this->get($old)->assertNotFound();
    $this->get($this->ava->fresh()->portalUrl())->assertOk();

    $this->trainer->forceFill(['suspended_at' => now()])->save();
    $this->get($this->ava->fresh()->portalUrl())->assertNotFound();
});

it('lets the trainer email and reset the link from the client page', function () {
    Mail::fake();
    $this->actingAs($this->trainer);

    $before = $this->ava->portalUrl();

    Livewire::test(Show::class, ['client' => $this->ava])
        ->assertSee($before)
        ->call('emailWalletLink');

    Mail::assertQueued(WalletLinkMail::class, fn (WalletLinkMail $m) => $m->hasTo('ava@example.com') && $m->client->is($this->ava));

    Livewire::test(Show::class, ['client' => $this->ava->fresh()])->call('resetWalletLink');
    expect($this->ava->fresh()->portalUrl())->not->toBe($before);

    $rendered = (new WalletLinkMail($this->ava->fresh()))->render();
    expect($rendered)->toContain('Open my Fitness Wallet')->toContain($this->ava->fresh()->portal_token)->toContain('$237.00');
});

it('includes the wallet link in session receipts', function () {
    $attendee = SessionAttendee::query()->whereHas('trainingSession', fn ($q) => $q->where('status', 'completed'))->first();

    $rendered = (new SessionCompletedMail($attendee))->render();

    expect($rendered)->toContain('View my Fitness Wallet')->toContain($this->ava->fresh()->portal_token);
});
