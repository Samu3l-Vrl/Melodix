<?php

namespace App\Exceptions;

/**
 * La playlist demandée ne peut pas être utilisée (HTTP 422) : lien non reconnu, source
 * indisponible, playlist introuvable ou vide. Le message est destiné à l'hôte.
 */
class PlaylistException extends JukeboxException
{
    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'playlist_unavailable';
    }
}
