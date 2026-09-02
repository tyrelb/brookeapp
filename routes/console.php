<?php

use Illuminate\Support\Facades\Schedule;

// Post membership fees each morning (Pacific time) for clients whose billing day has arrived.
Schedule::command('billing:post-monthly-fees')
    ->dailyAt('06:00')
    ->timezone('America/Vancouver')
    ->withoutOverlapping();
