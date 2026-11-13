<?php

use App\Http\Controllers\Api\GuestController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\RoomStateController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes "api" : sans état (pas de session, pas de cookie, pas de CSRF)
|--------------------------------------------------------------------------
| Préfixe automatique : /api. Utilisées par la télécommande invité (jeton dans
| l'en-tête X-Guest-Token) et par la resynchronisation d'état des deux écrans.
*/

Route::prefix('/rooms/{room}')->name('api.rooms.')->group(function () {
    // Resync : état complet + version. Répond même pour un salon fermé (le client affiche "salon fermé").
    Route::get('/state', RoomStateController::class)->name('state');

    Route::middleware('room.open')->group(function () {
        // Pseudo -> jeton invité.
        Route::post('/guests', [GuestController::class, 'store'])->name('guests.store');

        // --- Invité identifié par X-Guest-Token ---------------------------------
        Route::middleware('guest.token')->group(function () {
            Route::get('/guests/me', [GuestController::class, 'me'])->name('guests.me');
            Route::get('/search', SearchController::class)->name('search');
            Route::post('/queue', [QueueController::class, 'store'])->name('queue.store');
        });
    });
});
