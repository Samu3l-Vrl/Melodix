<?php

namespace App\Http\Middleware;

use App\Models\Guest;
use App\Models\Room;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie un invité à partir de l'en-tête `X-Guest-Token` (alias : `guest.token`).
 *
 * Pas de session ni de cookie : le jeton vit dans le localStorage du téléphone.
 * L'invité résolu est rangé dans les attributs de la requête (`$request->attributes->get('guest')`).
 */
class ResolveGuest
{
    public const HEADER = 'X-Guest-Token';

    /** On n'écrit `last_seen_at` qu'une fois par minute au plus : évite un UPDATE par frappe de recherche. */
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header(self::HEADER, '');
        $room = $request->route('room');

        // Longueur vérifiée avant la requête SQL : inutile d'interroger la base pour un jeton malformé.
        if (! $room instanceof Room || strlen($token) !== Guest::TOKEN_LENGTH) {
            return $this->unauthorized();
        }

        // Le jeton doit appartenir à CE salon : un jeton obtenu dans un salon ne vaut rien dans un autre.
        $guest = Guest::query()
            ->where('token', $token)
            ->where('room_id', $room->id)
            ->first();

        if ($guest === null) {
            return $this->unauthorized();
        }

        if ($guest->last_seen_at === null || $guest->last_seen_at->lt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
            // forceFill + saveQuietly : pas d'events Eloquent, pas de modification de updated_at parasite.
            $guest->timestamps = false;
            $guest->forceFill(['last_seen_at' => now()])->saveQuietly();
            $guest->timestamps = true;
        }

        $request->attributes->set('guest', $guest);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'message' => 'Jeton invité manquant ou invalide.',
            'code' => 'guest_unauthenticated',
        ], 401);
    }
}
