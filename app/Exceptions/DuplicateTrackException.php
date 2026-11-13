<?php

namespace App\Exceptions;

/**
 * Le morceau est déjà en attente ou en cours de lecture dans ce salon (HTTP 409).
 */
class DuplicateTrackException extends JukeboxException
{
    public function __construct()
    {
        parent::__construct('Ce morceau est déjà dans la file.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'duplicate_track';
    }
}
