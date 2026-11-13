<?php

namespace App\Services;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Throwable;

/**
 * Point de passage unique pour diffuser un event de salon.
 *
 * La diffusion est "best effort" : si Reverb est injoignable, l'action de l'utilisateur
 * a quand même été validée en base. On journalise et on continue ; les clients
 * rattraperont l'état via le contrat de resynchronisation (trou de version -> refetch).
 * Sans ce garde-fou, une panne de Reverb transformerait chaque ajout en erreur 500
 * alors que le morceau est bel et bien dans la file.
 */
class RoomBroadcaster
{
    public function send(ShouldBroadcast $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
