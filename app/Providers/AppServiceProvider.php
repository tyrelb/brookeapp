<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        // Route middleware only runs on the page load; re-run these on every Livewire action so a
        // suspended trainer or a demoted admin with a tab open is stopped at their next click.
        Livewire::addPersistentMiddleware([
            EnsureUserIsNotSuspended::class,
            EnsureUserIsAdmin::class,
            EnsureEmailIsVerified::class,
        ]);

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(8)->uncompromised()
            : Password::min(8));

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
