<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomStateResource;
use App\Models\Room;

/**
 * GET /api/rooms/{code}/state — état complet du salon + `version`.
 *
 * C'est le filet de sécurité du temps réel : dès qu'un client détecte un trou de version
 * ou se reconnecte, il appelle cet endpoint et repart d'un état sûr. Public, comme le canal
 * `room.{code}` : il n'expose rien de plus que ce que l'écran de la soirée affiche déjà.
 */
class RoomStateController extends Controller
{
    public function __invoke(Room $room): RoomStateResource
    {
        return RoomStateResource::for($room);
    }
}
