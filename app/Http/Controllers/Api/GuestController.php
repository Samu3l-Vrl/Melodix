<?php

namespace App\Http\Controllers\Api;

use App\Events\GuestJoined;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuestRequest;
use App\Http\Resources\GuestResource;
use App\Models\Guest;
use App\Models\Room;
use App\Services\RoomBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entrée des invités dans un salon : un pseudo contre un jeton.
 */
class GuestController extends Controller
{
    /** POST /api/rooms/{code}/guests — crée l'invité et renvoie son jeton (une seule fois). */
    public function store(StoreGuestRequest $request, Room $room, RoomBroadcaster $broadcaster): JsonResponse
    {
        $guest = $room->guests()->create([
            'nickname' => $request->validated('nickname'),
            'token' => Guest::generateToken(),
            'last_seen_at' => now(),
        ]);

        // Notification réservée à l'hôte (canal privé) : ne touche pas à l'état versionné.
        $broadcaster->send(GuestJoined::fromGuest($room, $guest));

        return response()->json([
            'guest' => (new GuestResource($guest))->resolve($request),
            // Le jeton est `hidden` sur le modèle : il n'apparaît que dans cette réponse.
            'token' => $guest->token,
        ], 201);
    }

    /**
     * GET /api/rooms/{code}/guests/me — vérifie un jeton stocké dans le localStorage.
     * 200 = reprise de session possible ; 401 (middleware) = le client doit redemander un pseudo.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Guest $guest */
        $guest = $request->attributes->get('guest');

        return response()->json([
            'guest' => (new GuestResource($guest))->resolve($request),
        ]);
    }
}
