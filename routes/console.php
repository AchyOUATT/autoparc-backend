<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rappels d'entretien du garage client, une fois par jour.
//
// 8 h plutot qu'en pleine nuit : un avis recu a 3 h est lu au reveil, noye
// parmi les autres. `withoutOverlapping` evite le cumul d'executions si le
// balayage s'eternise sur un gros parc.
Schedule::command('reminders:maintenance')
    ->dailyAt('08:00')
    ->withoutOverlapping();
