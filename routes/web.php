<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/password/reset/{token}', [\App\Http\Controllers\PasswordRecoveryController::class, 'show'])
    ->middleware('throttle:password-reset')->name('password.reset');
Route::post('/password/reset/{token}', [\App\Http\Controllers\PasswordRecoveryController::class, 'reset'])
    ->middleware('throttle:password-reset');

/*
| Les pages de partage d'un article — /v/{vehicle}, /p/{part}, /a/{accessory} —
| ne sont PAS ici : elles vivent dans routes/partage.php, enregistre hors du
| groupe 'web' par bootstrap/app.php. Les ramener dans ce fichier leur
| rendrait la session et les cookies que ce deplacement leur retire ; le
| pourquoi est ecrit en tete de routes/partage.php.
*/
