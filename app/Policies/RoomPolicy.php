<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

/**
 * Droits sur un salon. Règle unique de la V0 : seul le propriétaire (l'hôte qui l'a créé)
 * peut afficher l'écran hôte et piloter la lecture ou la file.
 * Laravel associe automatiquement cette policy au modèle Room (convention de nommage).
 */
class RoomPolicy
{
    /** Afficher l'écran hôte, piloter la lecture, retirer/réordonner/passer des morceaux, fermer le salon. */
    public function control(User $user, Room $room): bool
    {
        return $room->isOwnedBy($user);
    }
}
