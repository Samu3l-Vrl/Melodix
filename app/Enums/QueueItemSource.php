<?php

namespace App\Enums;

/**
 * Provenance d'un élément de file : ajouté par un invité, par l'hôte, ou tiré de la playlist de fond.
 */
enum QueueItemSource: string
{
    case Guest = 'guest';
    case Host = 'host';
    case Playlist = 'playlist';
}
