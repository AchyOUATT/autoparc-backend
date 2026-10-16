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

// Alerte de surconsommation, une fois par jour elle aussi.
//
// Un quart d'heure apres les rappels d'entretien, et non a la meme minute :
// les deux taches balaient le parc entier, et les faire partir ensemble
// doublerait la pointe de charge sans rien avancer. L'anti-repetition de
// 30 jours vit dans la commande, donc une cadence quotidienne ne produit pas
// une alerte quotidienne : elle garantit seulement qu'une derive est vue au
// plus tard le lendemain du plein qui la revele.
Schedule::command('alerts:fuel-anomaly')
    ->dailyAt('08:15')
    ->withoutOverlapping();
