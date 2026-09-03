<?php

use App\Livewire\Admin;
use App\Livewire\Clients;
use App\Livewire\Dashboard;
use App\Livewire\Plans;
use App\Livewire\Reports;
use App\Livewire\Services;
use App\Livewire\Sessions;
use App\Livewire\Settings\Business;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : view('welcome');
})->name('home');

Route::middleware(['auth', 'not-suspended', 'verified'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');

    Route::get('clients', Clients\Index::class)->name('clients.index');
    Route::get('clients/create', Clients\Form::class)->name('clients.create');
    Route::get('clients/{client}', Clients\Show::class)->name('clients.show');
    Route::get('clients/{client}/edit', Clients\Form::class)->name('clients.edit');

    Route::get('services', Services\Index::class)->name('services.index');

    Route::get('plans', Plans\Index::class)->name('plans.index');
    Route::get('plans/create', Plans\Form::class)->name('plans.create');
    Route::get('plans/{plan}/edit', Plans\Form::class)->name('plans.edit');

    Route::get('sessions', Sessions\Index::class)->name('sessions.index');
    Route::get('sessions/calendar', Sessions\Calendar::class)->name('sessions.calendar');
    Route::get('sessions/log', Sessions\Log::class)->name('sessions.log');
    Route::get('sessions/book', Sessions\Log::class)->defaults('mode', 'book')->name('sessions.book');
    Route::get('sessions/{trainingSession}', Sessions\Show::class)->name('sessions.show');

    Route::get('reports/monthly', Reports\Monthly::class)->name('reports.monthly');
    Route::get('reports/annual', Reports\Annual::class)->name('reports.annual');
});

Route::middleware(['auth', 'not-suspended', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\Dashboard::class)->name('dashboard');
    Route::get('trainers', Admin\Trainers\Index::class)->name('trainers.index');
    Route::get('trainers/{user}', Admin\Trainers\Show::class)->name('trainers.show');
});

Route::middleware(['auth', 'not-suspended'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    Route::get('settings/business', Business::class)->name('settings.business');
});

require __DIR__.'/auth.php';
