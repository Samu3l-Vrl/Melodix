<?php

use App\Http\Controllers\Auth\SpotifyAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Host\PlaybackController;
use App\Http\Controllers\Host\PlaylistController;
use App\Http\Controllers\Host\QueueController as HostQueueController;
use App\Http\Controllers\Host\SpotifyTokenController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes "web" : session, cookies, CSRF, Inertia
|--------------------------------------------------------------------------
| Tout ce qui concerne l'HÔTE passe ici, car l'hôte est authentifié par session.
| Les actions hôte répondent en JSON (état complet du salon) mais restent
| protégées par le jeton CSRF, envoyé par le client dans l'en-tête X-XSRF-TOKEN.
*/

// --- Public -----------------------------------------------------------------
Route::get('/', HomeController::class)->name('home');

Route::get('/auth/spotify/redirect', [SpotifyAuthController::class, 'redirect'])->name('auth.spotify.redirect');
Route::get('/auth/spotify/callback', [SpotifyAuthController::class, 'callback'])->name('auth.spotify.callback');

// Télécommande invité : aucune authentification, le code du salon suffit.
Route::get('/room/{room}/join', [RoomController::class, 'join'])
    ->middleware('room.open')
    ->name('rooms.join');

// --- Hôte authentifié (OAuth Spotify) ----------------------------------------
Route::middleware('auth')->group(function () {
    Route::post('/logout', [SpotifyAuthController::class, 'logout'])->name('logout');

    Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');

    Route::get('/room/{room}/host', [RoomController::class, 'host'])
        ->middleware('room.open')
        ->name('rooms.host');

    // Jeton pour le callback getOAuthToken du Web Playback SDK.
    Route::get('/host/spotify/token', SpotifyTokenController::class)->name('host.spotify.token');

    // Playlists Spotify de l'hôte, pour choisir une playlist de fond.
    Route::get('/host/spotify/playlists', [PlaylistController::class, 'index'])->name('host.spotify.playlists');

    // Actions sur un salon : l'appartenance est vérifiée par RoomPolicy::control
    // (dans les Form Requests ou via Gate::authorize).
    Route::prefix('/host/rooms/{room}')
        ->middleware('room.open')
        ->name('host.')
        ->group(function () {
            Route::post('/playback', PlaybackController::class)->name('playback');

            Route::post('/queue/advance', [HostQueueController::class, 'advance'])->name('queue.advance');
            Route::put('/queue/reorder', [HostQueueController::class, 'reorder'])->name('queue.reorder');
            Route::delete('/queue/{item}', [HostQueueController::class, 'destroy'])
                ->whereNumber('item')
                ->name('queue.destroy');

            Route::put('/playlist', [PlaylistController::class, 'update'])->name('playlist.update');
            Route::delete('/playlist', [PlaylistController::class, 'destroy'])->name('playlist.destroy');

            Route::post('/close', [RoomController::class, 'close'])->name('close');
        });
});
