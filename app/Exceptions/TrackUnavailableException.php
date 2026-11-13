<?php

namespace App\Exceptions;

/**
 * Le morceau demandé ne peut pas être ajouté (HTTP 422) : introuvable chez la source,
 * ou source désactivée pour ce salon (ex. Spotify sans hôte Premium).
 */
class TrackUnavailableException extends JukeboxException
{
    public function __construct(
        string $message = 'Ce morceau est introuvable ou indisponible.',
        private readonly string $reason = 'track_unavailable',
    ) {
        parent::__construct($message);
    }

    public static function providerDisabled(string $providerLabel): self
    {
        return new self("La lecture {$providerLabel} n'est pas disponible dans ce salon.", 'provider_disabled');
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
