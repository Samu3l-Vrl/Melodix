<?php

use App\Http\Middleware\EnsureRoomIsOpen;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveGuest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * Point d'assemblage de l'application (Laravel 11 : plus de Kernel HTTP/Console).
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Routes sans état, préfixées par /api : invités (jeton) et resynchronisation.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Enregistre aussi la route POST /broadcasting/auth (autorisation des canaux privés).
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Inertia s'ajoute au groupe "web" uniquement : les routes /api restent du JSON pur.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'room.open' => EnsureRoomIsOpen::class,
            'guest.token' => ResolveGuest::class,
        ]);

        // Il n'y a pas de page de connexion : un visiteur non authentifié qui ouvre une page
        // hôte est renvoyé à l'accueil (les appels JSON reçoivent, eux, un 401).
        $middleware->redirectGuestsTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Les erreurs métier (JukeboxException) savent se rendre elles-mêmes via render().
    })->create();
