<?php

use App\Livewire\Admin\Trainers\Show;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->trainer = User::factory()->create();
});

it('blocks non-admins from the admin area', function () {
    $this->actingAs($this->trainer);

    $this->get(route('admin.dashboard'))->assertForbidden();
    $this->get(route('admin.trainers.index'))->assertForbidden();
    $this->get(route('admin.trainers.show', $this->admin))->assertForbidden();
});

it('shows platform statistics and trainers to an admin', function () {
    Client::factory()->count(3)->create(['user_id' => $this->trainer->id]);
    User::factory()->unverified()->create(['name' => 'Unverified Pat', 'created_at' => now()->subDays(3)]);

    $this->actingAs($this->admin);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Trainers signed up')
        ->assertSee('Unverified Pat');

    $this->get(route('admin.trainers.index', ['q' => $this->trainer->email]))
        ->assertOk()
        ->assertSee($this->trainer->name)
        ->assertSeeInOrder([$this->trainer->name, '3']); // client count is not hidden by the tenant scope

    $this->get(route('admin.trainers.show', $this->trainer))
        ->assertOk()
        ->assertSee('Support actions')
        ->assertSee('Counts only');
});

it('sends password reset and verification emails, and activates accounts', function () {
    Notification::fake();
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($this->admin);

    Livewire::test(Show::class, ['user' => $unverified])
        ->call('sendPasswordReset')
        ->call('resendVerification')
        ->call('markVerified');

    Notification::assertSentTo($unverified, ResetPassword::class);
    Notification::assertSentTo($unverified, VerifyEmail::class);
    expect($unverified->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('suspends and reinstates trainers, and suspended trainers are logged out', function () {
    $this->actingAs($this->admin);

    Livewire::test(Show::class, ['user' => $this->trainer])->call('suspend');
    expect($this->trainer->fresh()->isSuspended())->toBeTrue();

    $this->actingAs($this->trainer->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
    $this->assertGuest();

    $this->actingAs($this->admin);
    Livewire::test(Show::class, ['user' => $this->trainer->fresh()])->call('unsuspend');
    expect($this->trainer->fresh()->isSuspended())->toBeFalse();

    // Admins cannot suspend themselves.
    Livewire::test(Show::class, ['user' => $this->admin])->call('suspend');
    expect($this->admin->fresh()->isSuspended())->toBeFalse();
});

it('records the last login time when a trainer logs in', function () {
    Livewire::test('auth.login')
        ->set('email', $this->trainer->email)
        ->set('password', 'password')
        ->call('login');

    expect($this->trainer->fresh()->last_login_at)->not->toBeNull();
});

it('grants and revokes admin access from the console', function () {
    $this->artisan('admin:grant', ['email' => $this->trainer->email])->assertSuccessful();
    expect($this->trainer->fresh()->isAdmin())->toBeTrue();

    $this->artisan('admin:grant', ['email' => $this->trainer->email, '--revoke' => true])->assertSuccessful();
    expect($this->trainer->fresh()->isAdmin())->toBeFalse();

    $this->artisan('admin:grant', ['email' => 'nobody@example.com'])->assertFailed();
});
