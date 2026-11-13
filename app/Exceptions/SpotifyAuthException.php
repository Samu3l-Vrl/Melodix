<?php

namespace App\Exceptions;

/**
 * Impossible d'obtenir un jeton Spotify valide pour l'hôte (HTTP 401) :
 * refresh token révoqué ou absent. L'hôte doit se reconnecter.
 */
class SpotifyAuthException extends JukeboxException
{
    public function __construct(string $message = 'Session Spotify expirée : reconnectez-vous.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'spotify_reauth_required';
    }
}
