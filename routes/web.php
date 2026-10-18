<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Les pages de partage d'un article — /v/{vehicle}, /p/{part}, /a/{accessory} —
| ne sont PAS ici : elles vivent dans routes/partage.php, enregistre hors du
| groupe 'web' par bootstrap/app.php. Les ramener dans ce fichier leur
| rendrait la session et les cookies que ce deplacement leur retire ; le
| pourquoi est ecrit en tete de routes/partage.php.
*/
