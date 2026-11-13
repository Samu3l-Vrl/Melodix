<?php

namespace App\Enums;

/**
 * Statut d'un salon. Un salon fermé (manuellement ou après 12 h d'inactivité)
 * refuse toute action mais reste en base pour l'historique.
 */
enum RoomStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
