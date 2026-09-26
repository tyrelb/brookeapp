<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Livewire\Admin\Trainers\Show as TrainerShow;
use App\Livewire\Sessions\Show as SessionShow;
use App\Mail\WalletLinkMail;
use App\Models\Client;
use App\Models\Gym;
use App\Models\Service;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\Ics;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;

it('re-checks suspension, admin and verification on every Livewire action', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(
        EnsureUserIsNotSuspended::class,
        EnsureUserIsAdmin::class,
        EnsureEmailIsVerified::class,
    );
});

it('stops an admin demoted mid-session from using admin actions', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $trainer = User::factory()->create();
    $this->actingAs($admin);

    $page = Livewire::test(TrainerShow::class, ['user' => $trainer]);
    $admin->forceFill(['is_admin' => false])->save();

    $page->call('toggleAdmin')->assertForbidden();

    expect($trainer->fresh()->is_admin)->toBeFalse();
});

it('refuses to move a session to another trainer\'s gym', function () {
    $trainer = User::factory()->create();
    $mine = Gym::factory()->create(['user_id' => $trainer->id]);
    $theirs = Gym::factory()->create(['user_id' => User::factory()->create()->id]);
    $session = TrainingSession::factory()->create([
        'user_id' => $trainer->id,
        'service_id' => Service::factory()->create(['user_id' => $trainer->id])->id,
        'gym_id' => $mine->id,
    ]);
    $this->actingAs($trainer);

    Livewire::test(SessionShow::class, ['trainingSession' => $session])
        ->call('setGym', (string) $theirs->id)
        ->assertHasErrors(['newGymId']);

    expect($session->fresh()->gym_id)->toBe($mine->id);
});

it('can close registration', function () {
    config(['auth.registration_enabled' => false]);

    $this->get('/register')->assertNotFound();
    $this->get('/login')->assertOk()->assertDontSee('Sign up');
    $this->get('/')->assertOk()->assertDontSee('Register');
});

it('throttles sign-up attempts per address', function () {
    foreach (range(1, 10) as $attempt) {
        Volt::test('auth.register')->set('email', 'not-an-email')->call('register')->assertHasErrors(['email' => 'email']);
    }

    Volt::test('auth.register')
        ->set('name', 'Late Comer')
        ->set('email', 'late@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasErrors(['email']);

    expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
});

it('throttles resending the verification email', function () {
    Notification::fake();
    $this->actingAs(User::factory()->unverified()->create());

    foreach (range(1, 4) as $attempt) {
        $page = Volt::test('auth.verify-email')->call('sendVerification');
    }

    Notification::assertCount(3);
    $page->assertSee('wait a few minutes');
});

it('does not log a suspended trainer in', function () {
    $trainer = User::factory()->create(['email' => 'gone@example.com']);
    $trainer->forceFill(['suspended_at' => now()])->save();

    Volt::test('auth.login')
        ->set('email', 'gone@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors(['email']);

    $this->assertGuest();
});

it('sends baseline security headers, and keeps the wallet page out of indexes and referrers', function () {
    $this->get('/login')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $client = Client::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->get($client->portalUrl())
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('keeps trainer-written Markdown out of client emails', function () {
    $trainer = User::factory()->create([
        'booking_instructions' => '[Pay here](https://evil.example/pay) ![](https://tracker.example/p.png)',
    ]);
    $client = Client::factory()->create(['user_id' => $trainer->id, 'email' => 'ava@example.com']);

    // Compile the template the way `php artisan optimize` does in production, not inside the mail renderer.
    app('blade.compiler')->compile(resource_path('views/mail/wallet-link.blade.php'));

    $html = (new WalletLinkMail($client))->render();

    expect($html)
        ->toContain('Pay here')
        ->not->toContain('href="https://evil.example/pay"')
        ->not->toContain('src="https://tracker.example/p.png"')
        ->toContain('href="'.$client->fresh()->portalUrl().'"'); // the app's own button still links
});

it('neutralises spreadsheet formulas in CSV text cells', function () {
    expect(csv_text('=HYPERLINK("https://evil.example")'))->toBe('\'=HYPERLINK("https://evil.example")')
        ->and(csv_text('+1'))->toBe("'+1")
        ->and(csv_text('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(csv_text('Ava Nguyen'))->toBe('Ava Nguyen')
        ->and(csv_text(''))->toBe('')
        ->and(csv_text(null))->toBe('');
});

it('quotes calendar-invite names and drops stray carriage returns', function () {
    expect(Ics::param('Brooke Fitness: "Studio"'))->toBe('"Brooke Fitness: Studio"')
        ->and(Ics::escape("Line one\rATTENDEE:mailto:x@example.com"))->toBe('Line oneATTENDEE:mailto:x@example.com');
});

it('refuses to seed demo accounts in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
        ->expectsOutputToContain('Refusing to seed demo accounts in production.');

    expect(User::count())->toBe(0);
});
