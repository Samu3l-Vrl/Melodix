<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canaux de diffusion
|--------------------------------------------------------------------------
| 1) `room.{code}` — canal PUBLIC. Un canal public n'a pas de callback d'autorisation :
|    n'importe quel client connecté à Reverb peut s'y abonner. On n'y diffuse donc que
|    l'état non sensible (file, lecture), c'est-à-dire ce que la TV affiche déjà.
|    Events : QueueUpdated (.queue.updated), PlaybackStateChanged (.playback.changed).
|
| 2) `private-room.{code}.host` — canal PRIVÉ, déclaré ci-dessous sans le préfixe
|    "private-" (Laravel et Echo l'ajoutent eux-mêmes). À l'abonnement, Echo appelle
|    POST /broadcasting/auth avec le cookie de session ; ce callback décide.
|    Event : GuestJoined (.guest.joined).
*/

Broadcast::channel('room.{code}.host', function (User $user, string $code): bool {
    // Seul le propriétaire du salon reçoit les events réservés à l'hôte.
    return Room::query()
        ->where('code', strtoupper($code))
        ->where('host_user_id', $user->id)
        ->exists();
});
