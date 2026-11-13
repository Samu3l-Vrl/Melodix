<?php

namespace App\Http\Middleware;

use App\Exceptions\RoomClosedException;
use App\Models\Room;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute requête visant un salon fermé (alias de middleware : `room.open`).
 * Premier filtre, bon marché ; les services revérifient sous verrou pour le cas
 * où le salon serait fermé entre ce contrôle et la mutation.
 */
class EnsureRoomIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $room = $request->route('room');

        if ($room instanceof Room && ! $room->isOpen()) {
            // Appels JSON : 410 avec un code exploitable par le client.
            if ($request->expectsJson()) {
                throw new RoomClosedException;
            }

            // Pages : simple 404, le salon "n'existe plus" pour un visiteur.
            abort(404);
        }

        return $next($request);
    }
}
