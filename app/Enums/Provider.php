<?php

namespace App\Enums;

/**
 * Sources musicales supportées.
 * Pour ajouter Apple Music ou Deezer : ajouter un case ici, une implémentation
 * de MusicProvider côté PHP et un PlayerAdapter côté TypeScript.
 */
enum Provider: string
{
    case Spotify = 'spotify';
    case YouTube = 'youtube';

    /** Libellé lisible affiché dans les messages d'erreur. */
    public function label(): string
    {
        return match ($this) {
            self::Spotify => 'Spotify',
            self::YouTube => 'YouTube',
        };
    }
}
