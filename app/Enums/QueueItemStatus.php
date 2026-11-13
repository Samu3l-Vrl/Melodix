<?php

namespace App\Enums;

/**
 * Cycle de vie d'un élément de file :
 * queued -> playing -> played (fin naturelle) ou skipped (skip hôte / erreur de lecture).
 */
enum QueueItemStatus: string
{
    case Queued = 'queued';
    case Playing = 'playing';
    case Played = 'played';
    case Skipped = 'skipped';

    /**
     * Statuts "actifs" : un morceau dans l'un de ces états ne peut pas être ré-ajouté (doublon).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Playing];
    }
}
